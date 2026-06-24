<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\fields\Matrix;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;

use verbb\supertable\fields\SuperTableField;

use yii\base\InvalidArgumentException;

class PluginContentMigration extends PluginMigration
{
    // Properties
    // =========================================================================

    protected ?int $contentSiteId = null;


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

        $fieldService = Craft::$app->getFields();

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

            if ($field) {
                // Handle global field content
                if ($field->context === 'global') {
                    // We have to use field instances, not just the field
                    foreach ($this->findFieldUsages($field) as $fieldLayoutUid) {
                        // Find content rows for each field instance
                        $sql = Craft::$app->getDb()->getQueryBuilder()->jsonExtract('content', [$fieldLayoutUid]);

                        $rows = (new Query())
                            ->select(['content', 'id', 'elementId', 'siteId'])
                            ->from('{{%elements_sites}}')
                            ->where([
                                'and',
                                ['not', ['content' => null]],
                                $sql . ' IS NOT NULL',
                            ])
                            ->all();

                        foreach ($rows as $row) {
                            if (Json::isJsonObject($row['content'])) {
                                $elementContent = Json::decode($row['content']) ?? [];
                                $fieldContent = $elementContent[$fieldLayoutUid] ?? '';

                                if (is_string($fieldContent) && Json::isJsonObject($fieldContent)) {
                                    $fieldContent = Json::decode($fieldContent) ?? [];
                                }

                                if ($fieldContent) {
                                    $this->contentSiteId = (int)$row['siteId'] ?: null;
                                    $settings = $this->convertModel($field, $fieldContent);
                                    $this->contentSiteId = null;

                                    if ($settings) {
                                        $elementContent[$fieldLayoutUid] = Json::encode($settings);

                                        // Direct database save on the content for performance, and not to mess with saving elements
                                        Db::update('{{%elements_sites}}', ['content' => $elementContent], ['id' => $row['id']]);

                                        $this->stdout('    > Migrated content for element #' . $row['elementId'], Console::FG_GREEN);
                                    } else {
                                        // Null model is okay, that's just an empty field content
                                        if ($settings !== null) {
                                            $this->stdout('    > Unable to convert content for element #' . $row['elementId'], Console::FG_RED);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Check for Vizy fields, a little different
            if ($this->isPluginInstalledAndEnabled('vizy')) {
                $this->migrateVizyContent($fieldData);
            }

            $this->stdout("    > Field “{$field['handle']}” content migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }


    // Protected Methods
    // =========================================================================

    protected function serializeMigratedLink(LinkInterface $link): array
    {
        if ($link instanceof ElementLink && $this->contentSiteId) {
            $link->linkSiteId = $this->contentSiteId;
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
        $uids = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            try {
                $fieldLayoutField = $layout->getField(fn(BaseField $layoutField) => (
                    $layoutField instanceof CustomField && $layoutField->getFieldUid() === $field->uid
                ));

                if ($fieldLayoutField) {
                    $uids[] = $fieldLayoutField->uid;
                }
            } catch (InvalidArgumentException) {

            }
        }

        return $uids;
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
}
