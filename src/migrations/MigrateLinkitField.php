<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use presseddigital\linkit\fields\LinkitField;
use presseddigital\linkit\models\Asset;
use presseddigital\linkit\models\Category;
use presseddigital\linkit\models\Email;
use presseddigital\linkit\models\Entry;
use presseddigital\linkit\models\Facebook;
use presseddigital\linkit\models\Instagram;
use presseddigital\linkit\models\LinkedIn;
use presseddigital\linkit\models\Phone;
use presseddigital\linkit\models\Twitter;
use presseddigital\linkit\models\Url;
use presseddigital\linkit\models\User;

class MigrateLinkitField extends PluginFieldMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        Asset::class => linkTypes\Asset::class,
        Category::class => linkTypes\Category::class,
        Email::class => linkTypes\Email::class,
        Entry::class => linkTypes\Entry::class,
        Phone::class => linkTypes\Phone::class,
        Url::class => linkTypes\Url::class,
        Twitter::class => linkTypes\Url::class,
        Facebook::class => linkTypes\Url::class,
        Instagram::class => linkTypes\Url::class,
        LinkedIn::class => linkTypes\Url::class,
        User::class => linkTypes\User::class,
    ];

    public string $oldFieldTypeClass = LinkitField::class;


    // Public Methods
    // =========================================================================

    public function processFieldSettings(): void
    {
        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']);
            $allowCustomText = $settings['allowCustomText'] ?? true;

            $types = [];

            foreach (($settings['types'] ?? []) as $key => $type) {
                $linkTypeClass = $this->getLinkType($key);

                if (!$linkTypeClass) {
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = self::getClassDisplayName($key);
                $linkType->handle = self::getLinkTypeHandle($types, $linkTypeClass::typeKey());
                $linkType->enabled = $type['enabled'] ?? false;
                $linkType->linkText = $type['customLabel'] ?? null;
                // A duplicate of the same kind was handed a random handle → it's a custom instance.
                $linkType->isCustom = $linkType->handle !== $linkTypeClass::typeKey();

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = self::normalizeElementLinkSources($type['sources'] ?? null);
                    $linkType->selectionLabel = $type['customSelectionLabel'] ?? null;
                } else {
                    $linkType->placeholder = $type['customPlaceholder'] ?? null;
                }

                $fieldLayout = self::getDefaultFieldLayout($linkType, $allowCustomText);
                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();
            }

            // Disable some Hyper link types that don't exist for Linkit, to ensure 1-for-1 migration. Still creates the link type.
            self::createDisabledLinkTypes($types, [
                linkTypes\Custom::class,
                linkTypes\Embed::class,
                linkTypes\FormieForm::class,
                linkTypes\Product::class,
                linkTypes\ShopifyProduct::class,
                linkTypes\Site::class,
                linkTypes\Variant::class,
            ]);

            // Order types by label
            usort($types, fn($a, $b) => $a['label'] <=> $b['label']);

            // Create a new Hyper field instance to have the settings validated correctly
            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);

            $newFieldConfig['newWindow'] = $settings['allowTarget'] ?? false;
            $newFieldConfig['linkTypes'] = $types;

            $newField = new HyperField($newFieldConfig);

            if (!$this->validateMigratedLinkTypeSettings($newField, $field['handle'])) {
                continue;
            }

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);

                continue;
            }

            $this->prepLinkTypes($newField);

            Db::update('{{%fields}}', ['type' => HyperField::class, 'settings' => Json::encode($newField->settings)], ['id' => $field['id']], [], true, $this->db);

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);

            $this->count++;
        }
    }
}
