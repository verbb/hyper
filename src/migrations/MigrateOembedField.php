<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

class MigrateOembedField extends PluginFieldMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [];

    public string $oldFieldTypeClass = 'wrav\\oembed\\fields\\OembedField';


    // Public Methods
    // =========================================================================

    public function processFieldSettings(): void
    {
        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $types = [];
            $linkType = new linkTypes\Embed();
            $linkType->label = Craft::t('hyper', 'Embed');
            $linkType->handle = self::getLinkTypeHandle($types, linkTypes\Embed::typeKey());
            $linkType->enabled = true;

            $fieldLayout = self::getDefaultFieldLayout($linkType, true, true, false);
            $linkType->layoutUid = StringHelper::UUID();
            $linkType->layoutConfig = $fieldLayout->getConfig();
            $types[] = $linkType->getSettingsConfig();

            self::createDisabledLinkTypes($types, [
                linkTypes\Asset::class,
                linkTypes\Category::class,
                linkTypes\Custom::class,
                linkTypes\Email::class,
                linkTypes\Entry::class,
                linkTypes\FormieForm::class,
                linkTypes\Passive::class,
                linkTypes\Phone::class,
                linkTypes\Product::class,
                linkTypes\ShopifyProduct::class,
                linkTypes\Site::class,
                linkTypes\Url::class,
                linkTypes\User::class,
                linkTypes\Variant::class,
            ]);

            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);
            $newFieldConfig['newWindow'] = true;
            $newFieldConfig['linkTypes'] = $types;
            $newFieldConfig['defaultLinkType'] = $linkType->handle;

            $newField = new HyperField($newFieldConfig);

            if (!$this->validateMigratedLinkTypeSettings($newField, $field['handle'])) {
                continue;
            }

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);
                continue;
            }

            $this->prepLinkTypes($newField);

            Db::update('{{%fields}}', [
                'type' => HyperField::class,
                'settings' => Json::encode($newField->settings),
            ], ['id' => $field['id']], [], true, $this->db);

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
            $this->count++;
        }
    }
}
