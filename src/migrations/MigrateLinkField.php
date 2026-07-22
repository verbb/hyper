<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

class MigrateLinkField extends PluginFieldMigration
{
    // Properties
    // =========================================================================

    // String FQCNs — field step only runs when flipbox Link is installed; avoids hard require at parse time.
    public array $typeMap = [
        'flipbox\\craft\\link\\types\\Asset' => linkTypes\Asset::class,
        'flipbox\\craft\\link\\types\\Category' => linkTypes\Category::class,
        'flipbox\\craft\\link\\types\\Email' => linkTypes\Email::class,
        'flipbox\\craft\\link\\types\\Entry' => linkTypes\Entry::class,
        'flipbox\\craft\\link\\types\\Url' => linkTypes\Url::class,
        'flipbox\\craft\\link\\types\\User' => linkTypes\User::class,
        'flipbox\\link\\types\\Asset' => linkTypes\Asset::class,
        'flipbox\\link\\types\\Category' => linkTypes\Category::class,
        'flipbox\\link\\types\\Email' => linkTypes\Email::class,
        'flipbox\\link\\types\\Entry' => linkTypes\Entry::class,
        'flipbox\\link\\types\\Url' => linkTypes\Url::class,
        'flipbox\\link\\types\\User' => linkTypes\User::class,
    ];

    public string $oldFieldTypeClass = 'flipbox\\craft\\link\\fields\\Link';


    // Public Methods
    // =========================================================================

    public function getOldFieldTypeClasses(): array
    {
        // Nested Matrix/ST fields may still store the pre-1.0 namespace.
        return [
            'flipbox\\craft\\link\\fields\\Link',
            'flipbox\\link\\fields\\Link',
        ];
    }

    public function processFieldSettings(): void
    {
        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']);

            $types = [];
            $identifierMap = [];
            $processedTypes = [];

            foreach (($settings['types'] ?? []) as $key => $type) {
                $oldClass = $type['class'] ?? null;
                $linkTypeClass = $this->getLinkType($oldClass);

                if (!$linkTypeClass) {
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = $type['label'] ?? $linkType::displayName();
                $linkType->handle = self::getLinkTypeHandle($types, $linkTypeClass::typeKey());
                $linkType->enabled = true;
                // A duplicate of the same kind was handed a random handle → it's a custom instance.
                $linkType->isCustom = $linkType->handle !== $linkTypeClass::typeKey();

                if (in_array($linkTypeClass, $processedTypes)) {
                    $linkType->handle = $key;
                    $linkType->isCustom = true;
                }

                $allowText = $type['allowText'] ?? true;

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = self::normalizeElementLinkSources($type['sources'] ?? null);
                } else {
                    $linkType->placeholder = $type['placeholder'] ?? null;
                }

                $fieldLayout = self::getDefaultFieldLayout($linkType, $allowText);
                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();

                $identifierMap[$key] = [
                    'handle' => $linkType->handle,
                    'class' => get_class($linkType),
                ];

                $processedTypes[] = $linkTypeClass;
            }

            // Disable some Hyper link types that don't exist for Link, to ensure 1-for-1 migration. Still creates the link type.
            self::createDisabledLinkTypes($types, [
                linkTypes\Custom::class,
                linkTypes\Embed::class,
                linkTypes\FormieForm::class,
                linkTypes\Phone::class,
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

            $newFieldConfig['newWindow'] = true;
            $newFieldConfig['linkTypes'] = $types;
            $newFieldConfig['migrationData'] = $identifierMap;

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
