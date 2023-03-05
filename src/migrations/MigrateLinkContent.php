<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;

use flipbox\craft\link\fields\Link;
use flipbox\craft\link\types\Asset;
use flipbox\craft\link\types\Category;
use flipbox\craft\link\types\Email;
use flipbox\craft\link\types\Entry;
use flipbox\craft\link\types\Url;
use flipbox\craft\link\types\User;

class MigrateLinkContent extends PluginContentMigration
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

    public string $oldFieldTypeClass = Link::class;


    // Public Methods
    // =========================================================================

    public function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        $identifier = $oldSettings['identifier'] ?? null;
        $linkTypeInfo = $field->migrationData[$identifier] ?? null;
        $linkTypeClass = $linkTypeInfo['class'] ?? null;
        $linkTypeHandle = $linkTypeInfo['handle'] ?? null;

        $hyperType = $oldSettings[0]['type'] ?? null;

        if (str_contains($hyperType, 'verbb\\hyper')) {
            $this->stdout('    > Content already migrated to Hyper content.', Console::FG_GREEN);

            return null;
        }

        // Return `null` for an empty field, or already migrated to Hyper.
        // `false` for when unable to find matching new type.
        if (!$linkTypeClass || !$linkTypeHandle) {
            return false;
        }

        $link = new $linkTypeClass();
        $link->handle = $linkTypeHandle;
        $link->linkValue = $oldSettings['url'] ?? $oldSettings['email'] ?? $oldSettings['elementId'] ?? null;
        $link->linkText = $oldSettings['overrideText'] ?? null;
        $link->newWindow = ($oldSettings['target'] ?? '') === '_blank';

        return [$link->getSerializedValues()];
    }
}
