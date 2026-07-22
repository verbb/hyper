<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;

class MigrateEntrifyCategories extends PluginContentMigration
{
    // Properties
    // =========================================================================

    public string $oldFieldTypeClass = HyperField::class;


    // Public Methods
    // =========================================================================

    public function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        if (!array_is_list($oldSettings)) {
            return null;
        }

        $entryHandle = $this->_resolveEntryHandle($field);

        if ($entryHandle === null) {
            $this->stdout('    > Field has no enabled Entry link type — cannot entrify Category links.', Console::FG_YELLOW);

            return null;
        }

        $changed = false;
        $links = [];

        foreach ($oldSettings as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!$this->_isCategoryLink($field, $row)) {
                $links[] = $row;
                continue;
            }

            $elementId = (int)($row['linkValue'] ?? 0);

            if (!$elementId) {
                $links[] = $row;
                continue;
            }

            // Same ID after Craft entrify — only the link type changes
            $row['linkTypeHandle'] = $entryHandle;
            $row['handle'] = $entryHandle;
            $row['type'] = linkTypes\Entry::class;
            $row['linkValue'] = $elementId;
            unset($row['fields']); // layout UIDs may differ between Category/Entry types

            $links[] = $row;
            $changed = true;
        }

        return $changed ? $links : null;
    }


    // Private Methods
    // =========================================================================

    private function _isCategoryLink(HyperField $field, array $row): bool
    {
        $type = $row['type'] ?? '';

        if (is_string($type) && (
            $type === linkTypes\Category::class ||
            str_ends_with($type, '\\Category')
        )) {
            return true;
        }

        $handle = $row['linkTypeHandle'] ?? $row['handle'] ?? null;

        if (!$handle) {
            return false;
        }

        foreach ($field->getLinkTypes() as $lt) {
            if ($lt instanceof linkTypes\Category && $lt->handle === $handle) {
                return true;
            }
        }

        return false;
    }

    private function _resolveEntryHandle(HyperField $field): ?string
    {
        foreach ($field->getLinkTypes() as $lt) {
            if ($lt instanceof linkTypes\Entry && $lt->enabled) {
                return $lt->handle;
            }
        }

        return null;
    }
}
