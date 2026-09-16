<?php
namespace verbb\hyper\content;

use verbb\hyper\fields\HyperField;

use craft\base\FieldInterface;
use craft\helpers\Json;

use RuntimeException;

/** Hyper owns link rows and their placement-keyed custom fields. */
final class RawContentAdapter
{
    // Public Methods
    // =========================================================================

    public function fieldClass(): string
    {
        return HyperField::class;
    }

    public function captureSchema(FieldInterface $field): array
    {
        $types = [];
        $aliases = [];
        foreach ($field->getLinkTypes() as $type) {
            $placements = [];
            $layout = $type->getFieldLayout();
            foreach ($layout?->getCustomFieldElements() ?? [] as $placement) {
                $placements[$placement->uid] = ['placementUid' => $placement->uid, 'fieldUid' => $placement->getFieldUid(), 'layoutUid' => $layout->uid];
            }
            $types[$type->handle] = $placements;
            $aliases[$type::class] = $type->handle;
            $aliases[$type::typeKey()] = $type->handle;
        }
        return ['types' => $types, 'aliases' => $aliases];
    }

    public function transform(mixed $value, array $schema, callable $visit): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $encoded = is_string($value);
        $rows = $encoded ? Json::decode($value) : $value;
        if (!is_array($rows) || !array_is_list($rows)) {
            // A source field converted to Hyper may still contain third-party data.
            // Its parent visitor receives that raw value; it is not yet a Hyper container.
            return $value;
        }
        $before = $rows;
        $seen = [];
        foreach ($rows as $index => &$row) {
            if (!is_array($row)) {
                throw new RuntimeException('Malformed raw Hyper link row.');
            }
            if (!isset($row['fields'])) {
                continue;
            }
            $uid = $row['uid'] ?? null;
            $type = $row['linkTypeHandle'] ?? $row['handle'] ?? ($schema['aliases'][$row['type'] ?? ''] ?? null);
            if (($uid !== null && (!is_string($uid) || $uid === '' || isset($seen[$uid]))) || !is_string($type) || !isset($schema['types'][$type])) {
                throw new RuntimeException('Missing or ambiguous captured Hyper link identity.');
            }
            // Pre-UID Hyper content is addressed by its row index and checked
            // source snapshot; do not manufacture a link identity during a patch.
            if ($uid !== null) $seen[$uid] = true;
            if (!is_array($row['fields'])) {
                throw new RuntimeException('Malformed raw Hyper custom field map.');
            }
            foreach ($row['fields'] as $key => $raw) {
                $placement = $schema['types'][$type][$key] ?? null;
                if (!$placement) {
                    continue;
                }
                $change = $visit($raw, $placement, ['kind' => 'hyper', 'linkUid' => $uid, 'linkTypeHandle' => $type,
                    'placementUid' => $key, 'index' => $index]);
                if ($change['action'] === 'remove') {
                    unset($row['fields'][$key]);
                } elseif ($change['action'] === 'replace') {
                    $row['fields'][$key] = $change['value'];
                }
            }
        }
        if ($rows === $before) {
            return $value;
        }
        return $encoded ? RawJson::encode(RawJson::preserve($value, $rows)) : $rows;
    }
}
