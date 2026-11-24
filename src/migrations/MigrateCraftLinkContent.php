<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\fields\Link as CraftLinkField;
use craft\fields\linktypes\BaseLinkType;
use craft\fields\linktypes\Url as CraftUrlLinkType;
use craft\helpers\Component;
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

    private ?array $_craftLinkTypes = null;


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

            // Craft persists these schemes in the value; Hyper adds them when rendering.
            if (is_string($link->linkValue)) {
                if ($link instanceof linkTypes\Email) {
                    $link->linkValue = preg_replace('/^mailto:/i', '', $link->linkValue);
                } elseif ($link instanceof linkTypes\Phone) {
                    $link->linkValue = preg_replace('/^tel:/i', '', $link->linkValue);
                }
            }
        }

        $link->linkText = isset($oldSettings['label']) && $oldSettings['label'] !== ''
            ? (string)$oldSettings['label']
            : null;

        $target = $oldSettings['target'] ?? null;
        $link->newWindow = $target === '_blank' || $target === true;

        if (isset($oldSettings['urlSuffix'])) {
            $link->urlSuffix = (string)$oldSettings['urlSuffix'];
        }

        if (isset($oldSettings['title'])) {
            $link->linkTitle = (string)$oldSettings['title'];
        }

        if (isset($oldSettings['class'])) {
            $link->classes = (string)$oldSettings['class'];
        }

        if (isset($oldSettings['ariaLabel'])) {
            $link->ariaLabel = (string)$oldSettings['ariaLabel'];
        }

        foreach (['id', 'rel'] as $attribute) {
            if (isset($oldSettings[$attribute])) {
                $link->customAttributes[] = ['attribute' => $attribute, 'value' => (string)$oldSettings[$attribute]];
            }
        }

        if ($oldSettings['download'] ?? false) {
            $filename = $oldSettings['filename'] ?? null;
            $link->customAttributes[] = [
                'attribute' => 'download',
                'value' => $filename !== null ? (string)$filename : '',
            ];
        }

        return $this->serializeMigratedLink($link);
    }


    // Protected Methods
    // =========================================================================

    /**
     * Craft 5.3 and 5.4 stored Link content as the bare value string, and Craft never migrated it to the
     * array shape introduced in 5.5. Craft still reads these strings by resolving the type from the value.
     */
    protected function convertStoredString(HyperField $field, string $value): ?array
    {
        // Matches `craft\fields\Link::normalizeValue()`, which treats an empty string as no link
        if (!$value) {
            return null;
        }

        $typeId = $this->_resolveCraftLinkType($value);
        $linkTypeClass = $this->getLinkType($typeId);

        // Every content migration visits every Hyper field, so only claim strings the field can hold. Others, such
        // as oEmbed URLs waiting for their own migration, are left for that migration.
        foreach ($field->getLinkTypes() as $configured) {
            if ($linkTypeClass && $configured::class === $linkTypeClass && $configured->enabled) {
                return ['type' => $typeId, 'value' => $value];
            }
        }

        return null;
    }


    // Private Methods
    // =========================================================================

    /**
     * Mirrors `craft\fields\Link::resolveType()`. The source field's selected types are gone once the field
     * step has run, so every registered type is checked. Their prefixes don't overlap, so the order only
     * matters for URL, which `Link::types()` always lists last and is also the fallback.
     */
    private function _resolveCraftLinkType(string $value): string
    {
        $this->_craftLinkTypes ??= array_map(
            fn(string $type) => Component::createComponent($type, BaseLinkType::class),
            CraftLinkField::types(),
        );

        foreach ($this->_craftLinkTypes as $typeId => $linkType) {
            if ($linkType->supports($value)) {
                return $typeId;
            }
        }

        return CraftUrlLinkType::id();
    }

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
