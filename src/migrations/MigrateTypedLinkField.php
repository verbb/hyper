<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Console;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use Exception;

use lenz\linkfield\fields\LinkField;

class MigrateTypedLinkField extends PluginFieldMigration
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

    public function processFieldSettings(): void
    {
        $fieldService = Craft::$app->getFields();

        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']);
            $allowCustomText = $settings['allowCustomText'] ?? true;
            $allowTarget = $settings['allowTarget'] ?? true;
            $customTextRequired = $settings['customTextRequired'] ?? true;
            $defaultLinkName = $settings['defaultLinkName'] ?? '';
            $defaultText = $settings['defaultText'] ?? '';
            $enableAllLinkTypes = $settings['enableAllLinkTypes'] ?? true;
            $enableAriaLabel = $settings['enableAriaLabel'] ?? true;
            $enableTitle = $settings['enableTitle'] ?? true;

            $types = [];

            foreach (($settings['typeSettings'] ?? []) as $key => $type) {
                $linkTypeClass = $this->getLinkType($key);

                if (!$linkTypeClass) {
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = $linkType::displayName();
                $linkType->handle = self::getLinkTypeHandle($types, 'default-' . StringHelper::toKebabCase($linkTypeClass));
                $linkType->enabled = $enableAllLinkTypes || ($type['enabled'] ?? false);
                $linkType->linkText = $defaultText;
                $linkType->isCustom = !str_starts_with($linkType->handle, 'default-');

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = $type['sources'] ?? '*';
                } else if ($linkType instanceof linkTypes\Site) {
                    $linkType->sites = $type['sites'] ?? null;

                    if (is_array($linkType->sites)) {
                        foreach ($linkType->sites as $siteKey => $siteId) {
                            if ($site = Craft::$app->getSites()->getSiteById($siteId)) {
                                $linkType->sites[$siteKey] = $site->uid;
                            }
                        }
                    }
                }

                $fieldLayout = self::getDefaultFieldLayout($allowCustomText, $enableTitle, $enableAriaLabel);
                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();
            }

            // Disable some Hyper link types that don't exist for Typed Link, to ensure 1-for-1 migration. Still creates the link type.
            self::createDisabledLinkTypes($types, [
                linkTypes\Embed::class,
                linkTypes\Variant::class,
            ]);

            // Order types by label
            usort($types, fn($a, $b) => $a['label'] <=> $b['label']);

            // Create a new Hyper field instance to have the settings validated correctly
            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);

            $newFieldConfig['newWindow'] = $allowTarget;
            $newFieldConfig['defaultLinkType'] = $this->getLinkType($defaultLinkName) ? 'default-' . StringHelper::toKebabCase($this->getLinkType($defaultLinkName)) : null;
            $newFieldConfig['linkTypes'] = $types;

            $newField = new HyperField($newFieldConfig);
            $newField->columnSuffix = StringHelper::randomString(8);

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);

                continue;
            }

            // We have to save the field instead of a settings update, because the plugin doesn't use the content table
            if ($newField->context === 'global') {
                if (!$fieldService->saveField($newField)) {
                    throw new Exception(Json::encode($newField->getErrors()));
                }
            }

            if (str_contains($newField->context, 'matrixBlockType')) {
                // Get the Matrix field, and the content table
                $blockTypeUid = explode(':', $newField->context)[1];

                $matrixFieldId = (new Query())
                    ->select(['fieldId'])
                    ->from('{{%matrixblocktypes}}')
                    ->where(['uid' => $blockTypeUid])
                    ->scalar();

                if ($matrixFieldId) {
                    $matrixField = Craft::$app->getFields()->getFieldById($matrixFieldId);

                    if ($matrixField) {
                        $this->migrateBlockField($matrixField, $newField);

                        // For complex fields like Matrix > ST > Matrix, check if this is the top-level
                        if (!$fieldService->saveField($matrixField)) {
                            $errors = $matrixField->getErrors();

                            // Check for blocktype errors too
                            foreach ($matrixField->getBlockTypes() as $blockType) {
                                $errors[$blockType->handle] = $blockType->getErrors();
                            }

                            throw new Exception(Json::encode(array_filter($errors)));
                        }
                    } else {
                        $this->stdout("    > Unable to find owner Matrix field for ID “{$matrixFieldId}”." . PHP_EOL, Console::FG_RED);
                    }
                } else {
                    $this->stdout("    > Unable to find owner Matrix field for context “{$newField->context}”." . PHP_EOL, Console::FG_RED);
                }
            }

            if (str_contains($newField->context, 'superTableBlockType')) {
                // Get the Super Table field, and the content table
                $blockTypeUid = explode(':', $newField->context)[1];

                $superTableFieldId = (new Query())
                    ->select(['fieldId'])
                    ->from('{{%supertableblocktypes}}')
                    ->where(['uid' => $blockTypeUid])
                    ->scalar();

                if ($superTableFieldId) {
                    $superTableField = Craft::$app->getFields()->getFieldById($superTableFieldId);

                    if ($superTableField) {
                        $this->migrateBlockField($superTableField, $newField);

                        // For complex fields like Matrix > ST > Matrix, check if this is the top-level
                        if (!$fieldService->saveField($superTableField)) {
                            $errors = $superTableField->getErrors();

                            // Check for blocktype errors too
                            foreach ($superTableField->getBlockTypes() as $blockType) {
                                $errors[] = $blockType->getErrors();
                            }

                            throw new Exception(Json::encode(array_filter($errors)));
                        }
                    } else {
                        $this->stdout("    > Unable to find owner Super Table field for ID “{$superTableFieldId}”." . PHP_EOL, Console::FG_RED);
                    }
                } else {
                    $this->stdout("    > Unable to find owner Super Table field for context “{$newField->context}”." . PHP_EOL, Console::FG_RED);
                }
            }

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }

    public function migrateBlockField($matrixField, $newField): void
    {
        $blockTypes = $matrixField->getBlockTypes();

        foreach ($blockTypes as $blockType) {
            if ($fieldLayout = $blockType->getFieldLayout()) {
                $tabs = $fieldLayout->getTabs();

                foreach ($tabs as $tab) {
                    $tabElements = $tab->getElements();

                    foreach ($tabElements as $tabElement) {
                        if ($tabElement instanceof CustomField) {
                            $tabField = $tabElement->getField();

                            // Using string checks fixes an issue when converting multiple fields in a single Matrix field
                            if ((string)$tabField->id === (string)$newField->id) {
                                $tabElement->setField($newField);
                            }
                        }
                    }

                    $tab->setElements($tabElements);
                }

                $fieldLayout->setTabs($tabs);

                $blockType->setFieldLayout($fieldLayout);
            }
        }

        $matrixField->setBlockTypes($blockTypes);
    }
}
