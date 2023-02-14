<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use flipbox\craft\link\fields\Link;
use flipbox\craft\link\types\Asset;
use flipbox\craft\link\types\Category;
use flipbox\craft\link\types\Email;
use flipbox\craft\link\types\Entry;
use flipbox\craft\link\types\Url;
use flipbox\craft\link\types\User;

class MigrateLink extends PluginMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        Asset::class => linkTypes\Asset::class,
        Category::class => linkTypes\Category::class,
        Email::class => linkTypes\Email::class,
        Entry::class => linkTypes\Entry::class,
        Url::class => linkTypes\Url::class,
        User::class => linkTypes\User::class,
    ];

    public array $identifierMap = [];

    public string $oldFieldTypeClass = Link::class;


    // Public Methods
    // =========================================================================

    public function processFieldSettings(): void
    {
        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']);

            $types = [];

            $processedTypes = [];

            foreach (($settings['types'] ?? []) as $key => $type) {
                $oldClass = $type['class'] ?? null;
                $linkTypeClass = $this->getLinkType($oldClass);

                if (!$linkTypeClass) {
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = $type['label'] ?? $linkType::displayName();
                $linkType->handle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
                $linkType->enabled = true;

                if (in_array($linkTypeClass, $processedTypes)) {
                    $linkType->handle = $key;
                    $linkType->isCustom = true;
                }

                $allowText = $type['allowText'] ?? true;

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = $type['sources'] ?? '*';
                } else {
                    $linkType->placeholder = $type['placeholder'] ?? null;
                }

                $fieldLayout = self::getDefaultFieldLayout($allowText);
                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();

                $this->identifierMap[$key] = $linkType;
                $processedTypes[] = $linkTypeClass;
            }

            // Create a new Hyper field instance to have the settings validated correctly
            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);

            $newFieldConfig['newWindow'] = true;
            $newFieldConfig['linkTypes'] = $types;

            $newField = new HyperField($newFieldConfig);

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);

                continue;
            }

            $this->prepLinkTypes($newField);

            Db::update('{{%fields}}', ['type' => HyperField::class, 'settings' => Json::encode($newField->settings)], ['id' => $field['id']], [], true, $this->db);

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }

    public function convertModel($oldSettings): bool|array
    {
        $identifier = $oldSettings['identifier'] ?? null;
        $linkType = $this->identifierMap[$identifier] ?? null;

        if (!$linkType) {
            return false;
        }

        $linkTypeClass = get_class($linkType);

        if (!$linkTypeClass) {
            return false;
        }

        $link = new $linkTypeClass();
        $link->handle = $linkType->handle;
        $link->linkValue = $oldSettings['url'] ?? $oldSettings['email'] ?? $oldSettings['elementId'] ?? null;
        $link->linkText = $oldSettings['overrideText'] ?? null;
        $link->newWindow = ($oldSettings['target'] ?? '') === '_blank';

        return [$link->getSerializedValues()];
    }
}
