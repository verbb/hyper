<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\helpers\Console;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use Exception;

use verbb\supertable\fields\SuperTableField;

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

                $enableSuffix = $type['allowCustomQuery'] ?? false;

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = self::normalizeElementLinkSources($type['sources'] ?? null);
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

                $fieldLayout = self::getDefaultFieldLayout($linkType, $allowCustomText, $enableTitle, $enableAriaLabel, $enableSuffix);
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

            if (!$this->validateMigratedLinkTypeSettings($newField, $field['handle'])) {
                continue;
            }

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);

                continue;
            }

            // We have to save the field instead of a settings update, because the plugin doesn't use the content table
            if ($newField->context === 'global') {
                if (!$this->saveFieldForMigration($fieldService, $newField)) {
                    throw new Exception(Json::encode($newField->getErrors()));
                }

                $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
            }

            if (str_contains($newField->context, 'matrixBlockType')) {
                // Super Table fields should already have been converted to Matrix. If they haven't, show warning.
                if ($this->db->tableExists('{{%matrixblocktypes}}')) {
                    throw new Exception('Ensure you run the Matrix migration first before proceeding.');
                }

                $this->stdout("    > Matrix field skipped, as no longer in use “{$newField->context}”." . PHP_EOL, Console::FG_YELLOW);
            }

            if (str_contains($newField->context, 'superTableBlockType')) {
                // Super Table fields should already have been converted to Matrix. If they haven't, show warning.
                if ($this->db->tableExists('{{%supertableblocktypes}}')) {
                    throw new Exception('Ensure you run the Super Table migration first before proceeding.');
                }

                $this->stdout("    > Super Table field skipped, as no longer in use “{$newField->context}”." . PHP_EOL, Console::FG_YELLOW);
            }

            $this->count++;
        }
    }
}
