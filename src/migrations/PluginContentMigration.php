<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\content\Change;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;
use verbb\hyper\models\LinkCollection;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Json;

class PluginContentMigration extends PluginMigration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        App::maxPowerCaptain();

        // Because content migrations run separately to field migrations, the fields have already been migrated
        // to Hyper. So look for all Hyper fields and we can check if it has invalid or valid content.
        $this->fields = (new Query())
            ->from('{{%fields}}')
            ->where(['type' => HyperField::class])
            ->all();

        // Update the field content
        $this->processFieldContent();

        $this->stdout('Finished Migration' . PHP_EOL, Console::FG_GREEN);

        return true;
    }

    public function processFieldContent(): void
    {
        foreach ($this->fields as $fieldData) {
            $this->stdout("Preparing to migrate field “{$fieldData['handle']}” ({$fieldData['uid']}) content.");

            // Fetch the field model because we'll need it later
            $field = Craft::$app->getFields()->getFieldById($fieldData['id']);

            // Nested Matrix / Super Table / entry-type Hyper fields are non-global.
            // Content::modify still walks their layout UIDs (and nested placements when applicable).
            if ($field instanceof HyperField) {
                $context = $field->context ?: 'global';

                if ($context !== 'global') {
                    $this->stdout("    > Nested/context field (`{$context}`) — migrating owner content.", Console::FG_YELLOW);
                }

                Hyper::$plugin->getContent()->modifyRaw($field, function(mixed $stored, $ref) use ($field) {
                    // Convert from the raw stored payload (Linkit / flipbox / Craft Link / oEmbed shapes).
                    // Do not use serializeValues() first — that only works after Hyper hydration.
                    $raw = ElementContentStore::decodeStored($ref->value) ?? [];

                    // Scalar URL fields (oEmbed) arrive as plain strings
                    if (is_string($raw)) {
                        $raw = ['url' => $raw];
                    }

                    if (!is_array($raw)) {
                        $raw = [];
                    }

                    if ($raw === []) {
                        return Change::unchanged();
                    }

                    $this->contentSiteId = $ref->siteId ?: null;

                    try {
                        $converted = $this->convertModel($field, $raw);
                    } finally {
                        $this->contentSiteId = null;
                    }

                    if ($converted === null) {
                        return Change::unchanged();
                    }

                    if ($converted === false) {
                        $path = $ref->jsonPath ?: $ref->layoutUid;
                        $this->stdout(
                            "    > Unable to convert content for element #{$ref->elementId}"
                            . ($path ? " (path `{$path}`)" : '')
                            . $this->describeConvertFailure($field, $raw),
                            Console::FG_RED,
                        );

                        return Change::unchanged();
                    }

                    if (is_array($converted)) {
                        $this->stdout('    > Migrated content for element #' . $ref->elementId, Console::FG_GREEN);
                        $this->getMigrationResult()?->incrementStat('elementsMigrated');

                        // Repairs to existing Hyper rows must preserve custom fields, unavailable
                        // types and legacy identifiers rather than reserializing the whole collection.
                        return Change::replace($this->isHyperLinkContent($raw)
                            ? $converted
                            : (new LinkCollection($field, $converted))->serializeValues());
                    }

                    return Change::unchanged();
                }, new ModifyOptions(
                    dryRun: $this->dryRun,
                    syncRelations: $this->syncRelations,
                    db: $this->db,
                    persistTransformedValues: true,
                ));
            }

            // Check for Vizy fields, a little different
            if ($this->isPluginInstalledAndEnabled('vizy')) {
                $this->migrateVizyContent($fieldData);
            }

            if ($field) {
                $this->stdout("    > Field “{$field->handle}” content migrated." . PHP_EOL, Console::FG_GREEN);
                $this->getMigrationResult()?->incrementStat('fieldsMigrated');
            }
        }
    }


    // Protected Methods
    // =========================================================================

    protected function isHyperLinkContent(array $content): bool
    {
        $rows = array_is_list($content) ? $content : [$content];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!empty($row['linkTypeHandle'])) {
                return true;
            }

            $type = $row['type'] ?? null;

            if (is_string($type) && (str_starts_with($type, 'verbb\\hyper\\links\\') || is_a($type, LinkInterface::class, true))) {
                return true;
            }
        }

        return false;
    }

    protected function repairMigratedLinks(HyperField $field, array $content): ?array
    {
        if (!$this->contentSiteId) {
            return null;
        }

        $isList = array_is_list($content);
        $rows = $isList ? $content : [$content];
        $changed = false;

        foreach ($rows as &$row) {
            if (!is_array($row) || !empty($row['linkSiteId'])) {
                continue;
            }

            // Current content is identified by the configured handle, including custom
            // instances. Legacy class identities remain usable even if their type is disabled.
            $handle = $row['linkTypeHandle'] ?? $row['handle'] ?? null;
            $type = $row['type'] ?? null;
            $isElement = is_string($handle) && $field->getLinkTypeByHandle($handle) instanceof ElementLink;

            if (!isset($row['linkTypeHandle']) && is_string($type)) {
                $isElement = is_a($type, ElementLink::class, true);
            }

            if ($isElement) {
                $row['linkSiteId'] = $this->contentSiteId;
                $changed = true;
            }
        }

        unset($row);

        return $changed ? ($isList ? $rows : $rows[0]) : null;
    }

    protected function describeConvertFailure(HyperField $field, array $raw): string
    {
        return '';
    }

    protected function serializeMigratedLink(LinkInterface $link): array
    {
        if ($link instanceof ElementLink && $this->contentSiteId) {
            $link->linkSiteId ??= $this->contentSiteId;
        }

        $this->castScalarLinkValue($link);

        return [$link->getSerializedValues()];
    }

    protected function castScalarLinkValue(LinkInterface $link): void
    {
        if (!$link instanceof linkTypes\Phone && !$link instanceof linkTypes\Email) {
            return;
        }

        if ($link->linkValue !== null && $link->linkValue !== '' && is_scalar($link->linkValue)) {
            $link->linkValue = (string)$link->linkValue;
        }
    }

    protected function findFieldUsages(FieldInterface $field): array
    {
        return (new ElementContentStore($this->db))->findLayoutUids($field);
    }

    protected function getElementContentForField(ElementInterface $element, FieldInterface $field, array $fieldValue): array
    {
        $fieldContent = [];

        // Get the field content as JSON, indexed by field layout element UID
        if ($fieldLayout = $element->getFieldLayout()) {
            foreach ($fieldLayout->getCustomFields() as $fieldLayoutField) {
                $sourceHandle = $fieldLayoutField->layoutElement?->getOriginalHandle() ?? $fieldLayoutField->handle;

                if ($field->handle === $sourceHandle) {
                    $fieldContent[$fieldLayoutField->layoutElement->uid] = $fieldValue;
                }
            }
        }

        // Fetch the current JSON content so we can merge in the new field content
        $oldContent = Json::decode((new Query())
            ->select(['content'])
            ->from('{{%elements_sites}}')
            ->where(['elementId' => $element->id, 'siteId' => $element->siteId])
            ->scalar() ?? '') ?? [];

        // Another sanity check just in cases where content is double encoded
        if (is_string($oldContent) && Json::isJsonObject($oldContent)) {
            $oldContent = Json::decode($oldContent);
        }

        return array_merge($oldContent, $fieldContent);
    }

    protected function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        return null;
    }
}
