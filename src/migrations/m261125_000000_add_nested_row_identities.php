<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;
use verbb\hyper\content\Change;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\content\RawContentAdapter;
use verbb\hyper\content\RawJson;
use verbb\hyper\fields\HyperField;
use verbb\hyper\services\EmbeddedFields;

use Craft;
use craft\base\FieldInterface;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\fields\Matrix;

use Generator;

/**
 * Matrix and Neo rows inside links are keyed by type and field handles. Rows now also record their type and
 * layout element UIDs when saved, so a later rename can be resolved. This adds them to rows saved before, while
 * their handles still match. Values under handles that no longer resolve are left as they are.
 */
class m261125_000000_add_nested_row_identities extends Migration
{
    // Constants
    // =========================================================================

    private const BATCH_SIZE = 500;


    // Properties
    // =========================================================================

    private array $_linkTypes = [];


    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (!$field instanceof HyperField || !$this->_hasRowsFields($field)) {
                continue;
            }

            // Raw writes add the keys without saving elements, so element dates, events and integrations are
            // untouched. The content API visits every site, draft and revision, including Hyper fields in nested owners.
            $time = $this->beginCommand("add row identities to Matrix and Neo content in links for {$field->handle} (field {$field->id})");

            $transform = function(mixed $stored) use ($field): array {
                $links = ElementContentStore::decodeStored($stored);

                if (!is_array($links) || !array_is_list($links)) {
                    return Change::unchanged();
                }

                $resolved = $this->_resolveLinks($field, $links);

                if ($resolved === $links) {
                    return Change::unchanged();
                }

                return Change::replace(is_string($stored) ? RawJson::encode(RawJson::preserve($stored, $resolved)) : $resolved);
            };

            // The content API reads each query's result into memory at once, so walk the content in batches.
            foreach ($this->_elementIdBatches() as $elementIds) {
                Hyper::$plugin->getContent()->modifyRaw($field, $transform, new ModifyOptions(syncRelations: false, elementIds: $elementIds, db: $this->db));
            }

            $this->endCommand($time);
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }


    // Private Methods
    // =========================================================================

    /**
     * Yields the element IDs of a few hundred content rows at a time, in order, covering every row once.
     */
    private function _elementIdBatches(): Generator
    {
        $after = 0;

        while ($elementIds = (new Query())
            ->select(['elementId'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['>', 'elementId', $after])
            ->orderBy(['elementId' => SORT_ASC])
            ->limit(self::BATCH_SIZE)
            ->column($this->db)
        ) {
            // The last element's other site rows are matched by its ID, so the next batch starts after it.
            $elementIds = array_values(array_unique(array_map('intval', $elementIds)));
            $after = end($elementIds);

            yield $elementIds;
        }
    }

    private function _hasRowsFields(HyperField $field): bool
    {
        foreach ($field->getLinkTypes() as $linkType) {
            foreach ($linkType->getFieldLayout()?->getCustomFields() ?? [] as $nested) {
                if ($this->_isRowsField($nested)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function _isRowsField(FieldInterface $field): bool
    {
        return $field instanceof Matrix || is_a($field, 'benf\\neo\\Field');
    }

    private function _resolveLinks(HyperField $field, array $links): array
    {
        if (!isset($this->_linkTypes[$field->uid])) {
            $placements = [];

            foreach ($field->getLinkTypes() as $linkType) {
                foreach ($linkType->getFieldLayout()?->getCustomFieldElements() ?? [] as $placement) {
                    $placements[$linkType->handle][$placement->uid] = $placement->getField();
                }
            }

            $this->_linkTypes[$field->uid] = [(new RawContentAdapter())->captureSchema($field), $placements];
        }

        [$schema, $placements] = $this->_linkTypes[$field->uid];

        foreach ($links as $index => $link) {
            if (!is_array($link) || !is_array($link['fields'] ?? null)) {
                continue;
            }

            // Match the link type the way the raw content API does. Unknown types are left alone.
            $type = $link['linkTypeHandle'] ?? $link['handle'] ?? ($schema['aliases'][$link['type'] ?? ''] ?? null);

            if (is_string($type) && !isset($placements[$type])) {
                $type = $schema['handleAliases'][$type] ?? $type;
            }

            if (!is_string($type) || !isset($placements[$type])) {
                continue;
            }

            foreach ($link['fields'] as $placementUid => $value) {
                $nested = $placements[$type][$placementUid] ?? null;

                if ($nested && $this->_isRowsField($nested)) {
                    $links[$index]['fields'][$placementUid] = $this->_resolveRows($nested, $value);
                }
            }
        }

        return $links;
    }

    private function _resolveRows(FieldInterface $field, mixed $value): mixed
    {
        $rowsKey = $field instanceof Matrix ? 'entries' : 'blocks';
        $isWrapped = is_array($value) && array_key_exists($rowsKey, $value);
        $rows = $isWrapped ? $value[$rowsKey] : $value;

        if (!is_array($rows)) {
            return $value;
        }

        $types = [];

        foreach ($field instanceof Matrix ? $field->getEntryTypes() : $field->getBlockTypes() as $type) {
            $types[$type->uid] = $type;
        }

        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            // Only rows saved before identities were recorded change. Resolving one also resolves the rows
            // nested in it. Rows that already have them are left exactly as saved.
            if (!array_key_exists('fieldUids', $row)) {
                $row = EmbeddedFields::resolveRows($field, [$key => $row])[$key];
            }

            $type = is_string($row['typeUid'] ?? null) ? ($types[$row['typeUid']] ?? null) : null;

            // Hyper fields inside rows are stored in this same JSON, out of the content API's reach.
            foreach ($type && is_array($row['fields'] ?? null) ? $type->getFieldLayout()?->getCustomFields() ?? [] : [] as $nested) {
                $nestedValue = $row['fields'][$nested->handle] ?? null;

                if ($nested instanceof HyperField && is_array($nestedValue) && array_is_list($nestedValue)) {
                    $row['fields'][$nested->handle] = $this->_resolveLinks($nested, $nestedValue);
                } elseif ($this->_isRowsField($nested) && is_array($nestedValue)) {
                    $row['fields'][$nested->handle] = $this->_resolveRows($nested, $nestedValue);
                }
            }

            $rows[$key] = $row;
        }

        if ($isWrapped) {
            $value[$rowsKey] = $rows;

            return $value;
        }

        return $rows;
    }
}
