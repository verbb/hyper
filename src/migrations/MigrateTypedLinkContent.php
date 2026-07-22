<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links as linkTypes;
use verbb\hyper\models\LinkCollection;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use lenz\linkfield\fields\LinkField;

class MigrateTypedLinkContent extends PluginContentMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        'asset' => linkTypes\Asset::class,
        'category' => linkTypes\Category::class,
        'custom' => linkTypes\Custom::class,
        'email' => linkTypes\Email::class,
        'entry' => linkTypes\Entry::class,
        'site' => linkTypes\Site::class,
        'tel' => linkTypes\Phone::class,
        'url' => linkTypes\Url::class,
        'user' => linkTypes\User::class,
        'craftCommerce-product' => linkTypes\Product::class,
    ];

    public string $oldFieldTypeClass = LinkField::class;
    public bool $resaveFields = false;


    // Public Methods
    // =========================================================================

    public function processFieldContent(): void
    {
        $lenzTableExists = $this->db->tableExists('{{%lenz_linkfield}}');

        foreach ($this->fields as $fieldData) {
            $this->stdout("Preparing to migrate field “{$fieldData['handle']}” ({$fieldData['uid']}) content.");

            $field = Craft::$app->getFields()->getFieldById($fieldData['id']);

            if (!$field instanceof HyperField) {
                $this->stdout("    > Skipping — field is not Hyper yet (run field migration first).", Console::FG_YELLOW);
                continue;
            }

            // Primary path: Typed Link’s dedicated table (global + Matrix block owners)
            if ($lenzTableExists) {
                $this->_migrateFromLenzTable($field, (int)$fieldData['id']);
            } else {
                $this->stdout('    > `lenz_linkfield` table not found — skipping table-backed content.', Console::FG_YELLOW);
            }

            // Vizy embeds Typed Link JSON inside block content (Matrix > Vizy > link)
            if ($this->isPluginInstalledAndEnabled('vizy')) {
                $this->migrateVizyContent($fieldData, $field);
            }

            $this->stdout("    > Field “{$field->handle}” content migrated." . PHP_EOL, Console::FG_GREEN);
            $this->getMigrationResult()?->incrementStat('fieldsMigrated');
        }
    }

    public function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        // Already Hyper (list of link rows)
        if (array_is_list($oldSettings) && isset($oldSettings[0]) && is_array($oldSettings[0])) {
            $hyperType = $oldSettings[0]['type'] ?? null;
            $handle = $oldSettings[0]['linkTypeHandle'] ?? $oldSettings[0]['handle'] ?? null;

            if ($handle || (is_string($hyperType) && str_contains($hyperType, 'verbb\\hyper'))) {
                $this->stdout('    > Content already migrated to Hyper content.', Console::FG_GREEN);

                return null;
            }
        }

        $oldType = $oldSettings['type'] ?? null;

        if (!$oldType) {
            return null;
        }

        $linkTypeClass = $this->getLinkType($oldType);

        if (!$linkTypeClass) {
            $this->stdout("    > Unable to migrate Typed Link type “{$oldType}”.", Console::FG_RED);

            return false;
        }

        // Dual schema: classic lenz columns vs Vizy/inline `{ type, value, customText, … }`
        $linkValue = $oldSettings['linkedUrl'] ?? $oldSettings['value'] ?? null;
        $linkedId = $oldSettings['linkedId'] ?? null;
        $linkedSiteId = $oldSettings['linkedSiteId'] ?? $oldSettings['siteId'] ?? null;

        // Vizy element links often store the element id in `value` as a string/int
        if ($linkedId === null && $linkValue !== null && $linkValue !== '' && is_numeric($linkValue)) {
            $elementTypes = ['asset', 'category', 'entry', 'user', 'craftCommerce-product'];

            if (in_array((string)$oldType, $elementTypes, true)) {
                $linkedId = (int)$linkValue;
                $linkValue = null;
            }
        }

        // Special-case for using anchor links for URL types. These should be switched to the Custom Link Type.
        if (is_string($linkValue) && str_starts_with($linkValue, '#') && $linkTypeClass === linkTypes\Url::class) {
            $linkTypeClass = linkTypes\Custom::class;
        }

        // Preserve query strings like `&region=` — never HTML-entity-decode URL values
        if (is_string($linkValue)) {
            $linkValue = $this->preserveUrlEncoding($linkValue);
        }

        $link = new $linkTypeClass();
        // Fallback handle; overridden below by the field’s configured handle when present.
        $link->handle = $linkTypeClass::typeKey();
        $link->field = $field;

        foreach ($field->getLinkTypes() as $configured) {
            if ($configured::class === $linkTypeClass && $configured->enabled) {
                $link->handle = $configured->handle;
                break;
            }
        }

        $link->linkValue = $linkValue;

        // Advanced attrs: classic `payload` JSON column, or flat Vizy keys
        $advanced = [];

        if (!empty($oldSettings['payload'])) {
            $decoded = is_string($oldSettings['payload'])
                ? Json::decode($oldSettings['payload'], false)
                : $oldSettings['payload'];

            // Use associative array without HTML entity decoding
            if (is_string($oldSettings['payload'])) {
                $advanced = json_decode($oldSettings['payload'], true) ?: [];
            } elseif (is_array($decoded)) {
                $advanced = $decoded;
            }
        }

        $link->ariaLabel = $advanced['ariaLabel'] ?? $oldSettings['ariaLabel'] ?? null;
        $link->linkText = $advanced['customText'] ?? $oldSettings['customText'] ?? null;
        $link->linkTitle = $advanced['title'] ?? $oldSettings['title'] ?? null;

        $urlSuffix = $advanced['customQuery'] ?? $oldSettings['customQuery'] ?? null;

        if (is_string($urlSuffix)) {
            $link->urlSuffix = $this->preserveUrlEncoding($urlSuffix);
        }

        $target = $advanced['target'] ?? $oldSettings['target'] ?? '';
        $link->newWindow = $target === '_blank';

        if ($link instanceof ElementLink) {
            $link->linkSiteId = $linkedSiteId ?: $this->contentSiteId;
            $link->linkValue = $linkedId;
        }

        if ($link instanceof linkTypes\Site) {
            $siteId = $linkedSiteId ?: (is_numeric($oldSettings['value'] ?? null) ? (int)$oldSettings['value'] : null);

            if ($siteId) {
                if ($siteUid = Db::uidById(Table::SITES, $siteId)) {
                    $link->linkValue = $siteUid;
                }
            }
        }

        $this->castScalarLinkValue($link);

        return $this->serializeMigratedLink($link);
    }


    // Protected Methods
    // =========================================================================

    /**
     * Undo accidental HTML entity decode of query strings (`&region=` → `®ion=`).
     */
    protected function preserveUrlEncoding(string $value): string
    {
        // If migration previously corrupted `&reg` → `®`, restore the common case
        if (str_contains($value, '®ion=')) {
            $value = str_replace('®ion=', '&region=', $value);
            $value = str_replace('?&region=', '?region=', $value);
        }

        return $value;
    }


    // Private Methods
    // =========================================================================

    private function _migrateFromLenzTable(HyperField $field, int $fieldId): void
    {
        $contentRows = (new Query())
            ->select(['*'])
            ->from('{{%lenz_linkfield}}')
            ->where(['fieldId' => $fieldId])
            ->all();

        foreach ($contentRows as $row) {
            $this->contentSiteId = isset($row['siteId']) ? (int)$row['siteId'] : null;
            $settings = $this->convertModel($field, $row);
            $this->contentSiteId = null;

            $elementId = (int)($row['elementId'] ?? 0);
            $siteId = (int)($row['siteId'] ?? 0);

            if ($settings === null) {
                continue;
            }

            if ($settings === false) {
                $this->stdout('    > Unable to convert content for element #' . $elementId, Console::FG_RED);
                continue;
            }

            $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId ?: null);

            if (!$element) {
                // orphan Matrix/owner rows: warn and continue
                $this->stdout(
                    '    > Unable to find element #' . $elementId . ' and site #' . $siteId . ' — skipping orphan Typed Link row.',
                    Console::FG_YELLOW
                );
                continue;
            }

            if ($this->dryRun) {
                $this->stdout('    > Dry-run: would migrate element #' . $elementId, Console::FG_GREEN);
                $this->getMigrationResult()?->incrementStat('elementsMigrated');
                continue;
            }

            $newContent = $this->getElementContentForField($element, $field, $settings);

            // Encode explicitly — avoid HTML entity side-effects
            Db::update('{{%elements_sites}}', [
                'content' => json_encode($newContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ], [
                'elementId' => $elementId,
                'siteId' => $siteId,
            ], [], true, $this->db);

            if ($this->syncRelations) {
                $collection = new LinkCollection($field, $settings, $element);
                Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($field, $element, $collection);
            }

            $this->stdout('    > Migrated content for element #' . $elementId, Console::FG_GREEN);
            $this->getMigrationResult()?->incrementStat('elementsMigrated');
        }
    }
}
