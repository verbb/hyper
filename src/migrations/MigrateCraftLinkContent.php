<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\fields\Link as CraftLinkField;
use craft\helpers\Console;
use craft\helpers\StringHelper;

/**
 * Migrates Craft 5.3+ native Link field content (`LinkData` columns) to Hyper link JSON.
 */
class MigrateCraftLinkContent extends PluginContentMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        'asset' => linkTypes\Asset::class,
        'category' => linkTypes\Category::class,
        'email' => linkTypes\Email::class,
        'entry' => linkTypes\Entry::class,
        'phone' => linkTypes\Phone::class,
        'tel' => linkTypes\Phone::class,
        'sms' => linkTypes\Url::class,
        'url' => linkTypes\Url::class,
        'product' => linkTypes\Product::class,
    ];

    public string $oldFieldTypeClass = CraftLinkField::class;


    // Public Methods
    // =========================================================================

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

        $typeId = $oldSettings['type'] ?? null;
        $value = $oldSettings['value'] ?? null;

        if (!$typeId || $value === null || $value === '') {
            return null;
        }

        $linkTypeClass = $this->getLinkType((string)$typeId);

        if (!$linkTypeClass) {
            $this->stdout("    > Unable to migrate Craft link type “{$typeId}”.", Console::FG_RED);

            return false;
        }

        $link = new $linkTypeClass();
        // Fallback handle; overridden below by the field’s configured handle when present.
        $link->handle = $linkTypeClass::typeKey();
        $link->field = $field;

        // Prefer the field’s configured handle when present
        foreach ($field->getLinkTypes() as $configured) {
            if ($configured::class === $linkTypeClass && $configured->enabled) {
                $link->handle = $configured->handle;
                break;
            }
        }

        if ($link instanceof ElementLink) {
            $parsed = $this->_parseCraftElementValue((string)$value);

            if ($parsed === null) {
                $this->stdout("    > Unable to parse element link value “{$value}”.", Console::FG_RED);

                return false;
            }

            $link->linkValue = $parsed['elementId'];
            $link->linkSiteId = $parsed['siteId'] ?? $this->contentSiteId;
        } else {
            $link->linkValue = is_scalar($value) ? (string)$value : null;
        }

        $link->linkText = isset($oldSettings['label']) && $oldSettings['label'] !== ''
            ? (string)$oldSettings['label']
            : null;

        $target = $oldSettings['target'] ?? null;
        $link->newWindow = $target === '_blank' || $target === true;

        if (!empty($oldSettings['urlSuffix'])) {
            $link->urlSuffix = (string)$oldSettings['urlSuffix'];
        }

        if (!empty($oldSettings['title'])) {
            $link->linkTitle = (string)$oldSettings['title'];
        }

        if (!empty($oldSettings['class'])) {
            $link->classes = (string)$oldSettings['class'];
        }

        if (!empty($oldSettings['ariaLabel'])) {
            $link->ariaLabel = (string)$oldSettings['ariaLabel'];
        }

        return $this->serializeMigratedLink($link);
    }


    // Private Methods
    // =========================================================================

    private function _parseCraftElementValue(string $value): ?array
    {
        if (preg_match('/^{(?P<elementType>[\w\\\\]+):(?P<elementId>\d+)(?:@(?P<siteId>\d+))?/', $value, $matches)) {
            return [
                'elementId' => (int)$matches['elementId'],
                'siteId' => isset($matches['siteId']) ? (int)$matches['siteId'] : null,
            ];
        }

        // Plain numeric id fallback
        if (ctype_digit($value)) {
            return [
                'elementId' => (int)$value,
                'siteId' => null,
            ];
        }

        return null;
    }
}
