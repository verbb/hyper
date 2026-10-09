<?php
namespace verbb\hyper\services;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Matrix;

/** Explicit hooks for fields whose JSON host replaces Craft's element-save lifecycle. */
final class EmbeddedFields
{
    // Static Methods
    // =========================================================================

    public static function serialize(ElementInterface $owner): array
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

                    foreach (self::serialize($row) as $uid => $nested) {
                        foreach ($row->getFieldLayout()->getCustomFieldElements() as $slot) {
                            if ($slot->uid === $uid) {
                                $serialized[$key]['fields'][$slot->getField()->handle] = $nested;
                            }
                        }
                    }
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
}
