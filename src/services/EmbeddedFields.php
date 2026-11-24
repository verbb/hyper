<?php
namespace verbb\hyper\services;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Matrix;

/** Explicit hooks for fields whose JSON host replaces Craft's element-save lifecycle. */
final class EmbeddedFields
{
    // Static Methods
    // =========================================================================

    /**
     * For a link, `$storedFields` is its saved `fields` map, keyed by placement UID. When given, unreadable
     * nested rows and values are restored from it instead of the link's own payload, which on a control panel
     * save only holds the rows editors could see.
     */
    public static function serialize(ElementInterface $owner, ?array $storedFields = null): array
    {
        $values = [];

        foreach ($owner->getFieldLayout()?->getCustomFieldElements() ?? [] as $placement) {
            $field = $placement->getField();
            $value = $owner->getFieldValue($field->handle);

            if (method_exists($field, 'serializeValueForEmbeddedOwner')) {
                $values[$placement->uid] = $field->serializeValueForEmbeddedOwner($value, $owner);
            } elseif ($field instanceof \verbb\hyper\fields\HyperField) {
                $values[$placement->uid] = $field->serializeValueForDb($value, $owner);
            } elseif (($field instanceof Matrix || is_a($field, 'benf\\neo\\Field')) && $value instanceof ElementQueryInterface) {
                // Overlay the serialized rows without cloning Craft elements:
                // cloning clears their owner context and can detach nested Vizy.
                $serialized = $field->serializeValue($value, $owner);
                $keys = array_keys($serialized);

                foreach (array_values($value->all()) as $index => $row) {
                    $key = $keys[$index];
                    $serialized[$key]['uid'] = $row->uid;

                    // Rows are keyed by handle for Craft and Neo. Keep the type and
                    // layout element UIDs so a renamed handle can be resolved on read.
                    $serialized[$key]['typeUid'] = $row->getType()->uid;
                    $serialized[$key]['fieldUids'] = [];

                    foreach ($row->getFieldLayout()->getCustomFieldElements() as $slot) {
                        $serialized[$key]['fieldUids'][$slot->getField()->handle] = $slot->uid;
                    }

                    foreach (self::serialize($row) as $uid => $nested) {
                        foreach ($row->getFieldLayout()->getCustomFieldElements() as $slot) {
                            if ($slot->uid === $uid) {
                                $serialized[$key]['fields'][$slot->getField()->handle] = $nested;
                            }
                        }
                    }
                }

                if ($owner instanceof \verbb\hyper\base\Link) {
                    // Saved content is still keyed by the handles it was written with, so resolve it first.
                    $source = $storedFields === null
                        ? ($owner->fields[$field->handle] ?? null)
                        : self::resolveRows($field, $storedFields[$placement->uid] ?? null);
                    $serialized = self::restoreUnreadableRows($field, $serialized, $source);
                }

                $values[$placement->uid] = $serialized;
            }

            if ($owner instanceof \verbb\hyper\base\Link && array_key_exists($placement->uid, $values)) {
                // Copies rebuild links from this raw map. Sync persisted child
                // values without replacing their normalized validation state.
                $owner->fields[$field->handle] = $values[$placement->uid];
            }
        }

        return $values;
    }

    public static function afterSave(ElementInterface $owner): void
    {
        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $owner->getFieldValue($field->handle);

            if (method_exists($field, 'afterEmbeddedOwnerSave')) {
                $field->afterEmbeddedOwnerSave($value, $owner);
            } elseif ($value instanceof \verbb\hyper\models\LinkCollection) {
                foreach ($value->getLinks() as $link) {
                    self::afterSave($link);
                }
            } elseif (($field instanceof Matrix || is_a($field, 'benf\\neo\\Field')) && $value instanceof ElementQueryInterface) {
                foreach ($value->all() as $row) {
                    self::afterSave($row);
                }
            }
        }
    }

    /**
     * Maps stored Matrix or Neo row handles to the current ones, using the type and
     * layout element UIDs saved with each row. Values the current layout can't place
     * stay in the payload, so restoring a handle brings them back.
     */
    public static function resolveRows(FieldInterface $field, mixed $value): mixed
    {
        return self::_mapRows($field, $value, function(array $row, ?object $type): array {
            if (!$type) {
                return $row;
            }

            $row['type'] = $type->handle;
            $row['typeUid'] = $type->uid;
            $slots = self::_slots($type);
            $slotsByUid = array_combine(array_map(fn($slot) => $slot->uid, $slots), $slots);
            $storedUids = is_array($row['fieldUids'] ?? null) ? $row['fieldUids'] : [];
            $fields = [];
            $fieldUids = [];
            $legacy = [];

            foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $handle => $fieldValue) {
                if (!array_key_exists($handle, $storedUids)) {
                    $legacy[$handle] = $fieldValue;

                    continue;
                }

                // Craft drops values for fields removed from a layout. Do the same here.
                if (!$slot = $slotsByUid[$storedUids[$handle]] ?? null) {
                    continue;
                }

                $current = $slot->getField()->handle;
                $fields[$current] = self::resolveRows($slot->getField(), $fieldValue);
                $fieldUids[$current] = $slot->uid;
            }

            // Values without a stored UID predate UID tracking. Keep them under their
            // handle. They also fill a renamed field left empty while unreadable.
            foreach ($legacy as $handle => $fieldValue) {
                $slot = $slots[$handle] ?? null;

                if (!array_key_exists($handle, $fields) || self::_isEmpty($fields[$handle])) {
                    $fields[$handle] = $slot ? self::resolveRows($slot->getField(), $fieldValue) : $fieldValue;
                }

                if ($slot) {
                    $fieldUids[$handle] = $slot->uid;
                }
            }

            $row['fields'] = $fields;
            $row['fieldUids'] = $fieldUids;

            return $row;
        });
    }

    /**
     * Returns resolved rows without the values the current layouts can't place.
     * Craft and Neo throw for a field handle that no longer exists.
     */
    public static function readableRows(FieldInterface $field, mixed $value): mixed
    {
        return self::_mapRows($field, $value, function(array $row, ?object $type): array {
            if (!$type || !is_array($row['fields'] ?? null)) {
                return $row;
            }

            $slots = self::_slots($type);
            $row['fields'] = array_intersect_key($row['fields'], $slots);

            foreach ($row['fields'] as $handle => $fieldValue) {
                $row['fields'][$handle] = self::readableRows($slots[$handle]->getField(), $fieldValue);
            }

            return $row;
        });
    }

    /**
     * Adds stored rows and values the current layouts can't read back into freshly
     * serialized rows. Otherwise saving after a type or field rename drops them.
     */
    public static function restoreUnreadableRows(FieldInterface $field, array $serialized, mixed $stored): array
    {
        $storedRows = self::_rows($field, $stored);

        if ($storedRows === null) {
            return $serialized;
        }

        $types = self::_types($field);
        $isNeo = !$field instanceof Matrix;
        $appendedLevel = 0;
        $levelOffset = null;
        $keysByUid = [];

        foreach ($serialized as $key => $row) {
            if (isset($row['uid'])) {
                $keysByUid[$row['uid']] = $key;
            }
        }

        foreach ($storedRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!$type = $types[$row['type'] ?? ''] ?? null) {
                // A type UID that no longer resolves means the type was removed from the field, which drops
                // its rows, as Neo and earlier versions of Hyper do. Older rows can't tell that from a rename,
                // so they're kept.
                if (is_string($row['typeUid'] ?? null) && !isset($types['uid:' . $row['typeUid']])) {
                    continue;
                }

                // Editors can't see, edit or remove these rows. Keep them after the readable ones.
                $next = count($serialized) + 1;

                while (array_key_exists('new' . $next, $serialized)) {
                    $next++;
                }

                // A Neo level is relative to the block before it, which is now a different block. Start
                // the kept blocks at the top level, keeping their nesting under each other.
                if ($isNeo) {
                    $level = (int)($row['level'] ?? 1);
                    $levelOffset ??= $level - 1;
                    $row['level'] = $appendedLevel = max(1, min($level - $levelOffset, $appendedLevel + 1));
                }

                $serialized['new' . $next] = $row;

                continue;
            }

            // Rows the editor removed stay removed.
            if (($key = $keysByUid[$row['uid'] ?? ''] ?? null) === null) {
                continue;
            }

            $slots = self::_slots($type);

            foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $handle => $fieldValue) {
                $current = $serialized[$key]['fields'][$handle] ?? null;

                if (!isset($slots[$handle])) {
                    $serialized[$key]['fields'][$handle] ??= $fieldValue;
                } elseif (is_array($current) && self::_isRowsField($slots[$handle]->getField())) {
                    $serialized[$key]['fields'][$handle] = self::restoreUnreadableRows($slots[$handle]->getField(), $current, $fieldValue);
                }
            }
        }

        return $serialized;
    }


    // Private Methods
    // =========================================================================

    private static function _isRowsField(FieldInterface $field): bool
    {
        return $field instanceof Matrix || is_a($field, 'benf\\neo\\Field');
    }

    /**
     * Applies a callback to each row of a raw Matrix or Neo value, keeping its shape.
     */
    private static function _mapRows(FieldInterface $field, mixed $value, callable $callback): mixed
    {
        $rows = self::_rows($field, $value);

        if ($rows === null) {
            return $value;
        }

        $types = self::_types($field);

        foreach ($rows as $key => $row) {
            if (!is_array($row) || !is_string($row['type'] ?? null)) {
                continue;
            }

            $type = null;

            if (is_string($row['typeUid'] ?? null)) {
                $type = $types['uid:' . $row['typeUid']] ?? null;
            }

            $rows[$key] = $callback($row, $type ?? $types[$row['type']] ?? null);
        }

        $rowsKey = $field instanceof Matrix ? 'entries' : 'blocks';

        if (is_array($value) && array_key_exists($rowsKey, $value)) {
            $value[$rowsKey] = $rows;

            return $value;
        }

        return $rows;
    }

    private static function _rows(FieldInterface $field, mixed $value): ?array
    {
        if (!is_array($value) || !self::_isRowsField($field)) {
            return null;
        }

        $rowsKey = $field instanceof Matrix ? 'entries' : 'blocks';
        $rows = array_key_exists($rowsKey, $value) ? $value[$rowsKey] : $value;

        return is_array($rows) ? $rows : null;
    }

    /**
     * Indexes a field's entry types or Neo block types by handle and by `uid:<uid>`.
     */
    private static function _types(FieldInterface $field): array
    {
        $types = [];

        foreach ($field instanceof Matrix ? $field->getEntryTypes() : $field->getBlockTypes() as $type) {
            $types[$type->handle] = $type;
            $types['uid:' . $type->uid] = $type;
        }

        return $types;
    }

    /**
     * Indexes a row type's custom field layout elements by their current handle.
     */
    private static function _slots(object $type): array
    {
        $slots = [];

        foreach ($type->getFieldLayout()?->getCustomFieldElements() ?? [] as $slot) {
            $slots[$slot->getField()->handle] = $slot;
        }

        return $slots;
    }

    private static function _isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
