<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;

class MigrateLinkContent extends PluginContentMigration
{
    // Properties
    // =========================================================================

    // String FQCNs so this class loads without flipbox installed (content step runs after fields migrate).
    public array $typeMap = [
        'flipbox\\craft\\link\\types\\Asset' => linkTypes\Asset::class,
        'flipbox\\craft\\link\\types\\Category' => linkTypes\Category::class,
        'flipbox\\craft\\link\\types\\Email' => linkTypes\Email::class,
        'flipbox\\craft\\link\\types\\Entry' => linkTypes\Entry::class,
        'flipbox\\craft\\link\\types\\Url' => linkTypes\Url::class,
        'flipbox\\craft\\link\\types\\User' => linkTypes\User::class,
        // Pre-1.0 namespace (still seen in older installs)
        'flipbox\\link\\types\\Asset' => linkTypes\Asset::class,
        'flipbox\\link\\types\\Category' => linkTypes\Category::class,
        'flipbox\\link\\types\\Email' => linkTypes\Email::class,
        'flipbox\\link\\types\\Entry' => linkTypes\Entry::class,
        'flipbox\\link\\types\\Url' => linkTypes\Url::class,
        'flipbox\\link\\types\\User' => linkTypes\User::class,
    ];

    public string $oldFieldTypeClass = 'flipbox\\craft\\link\\fields\\Link';

    private string $_lastConvertFailureDetail = '';


    // Public Methods
    // =========================================================================

    public function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        $this->_lastConvertFailureDetail = '';

        // Already a Hyper collection (v2/v3 content)
        if ($this->_looksLikeHyperCollection($oldSettings)) {
            $this->stdout('    > Content already migrated to Hyper content.', Console::FG_GREEN);

            return null;
        }

        $resolved = $this->_resolveLinkType($field, $oldSettings);

        if (!$resolved) {
            return false;
        }

        [$linkTypeClass, $linkTypeHandle] = $resolved;

        $link = new $linkTypeClass();
        $link->handle = $linkTypeHandle;
        $link->linkValue = $oldSettings['url'] ?? $oldSettings['email'] ?? $oldSettings['elementId'] ?? null;
        $link->linkText = $oldSettings['overrideText'] ?? null;
        $link->newWindow = ($oldSettings['target'] ?? '') === '_blank';

        return $this->serializeMigratedLink($link);
    }

    protected function describeConvertFailure(HyperField $field, array $raw): string
    {
        if ($this->_lastConvertFailureDetail !== '') {
            return ' — ' . $this->_lastConvertFailureDetail;
        }

        $identifier = $raw['identifier'] ?? null;
        $keys = implode(', ', array_keys($raw));
        $mapKeys = implode(', ', array_keys($field->migrationData));

        return " — flipbox identifier `" . ($identifier ?: '∅') . "`;"
            . " content keys [{$keys}];"
            . " migrationData keys [{$mapKeys}]";
    }


    // Private Methods
    // =========================================================================

    private function _resolveLinkType(HyperField $field, array $oldSettings): ?array
    {
        $identifier = $oldSettings['identifier'] ?? null;

        // Primary: field migration wrote identifier → Hyper type map.
        if ($identifier !== null && $identifier !== '') {
            $linkTypeInfo = $field->migrationData[$identifier] ?? null;
            $linkTypeClass = $linkTypeInfo['class'] ?? null;
            $linkTypeHandle = $linkTypeInfo['handle'] ?? null;

            if ($linkTypeClass && $linkTypeHandle) {
                return [$linkTypeClass, $linkTypeHandle];
            }

            $this->_lastConvertFailureDetail = "no migrationData for identifier `{$identifier}`"
                . ' (available: ' . implode(', ', array_keys($field->migrationData)) . ')';
        } else {
            $this->_lastConvertFailureDetail = 'missing flipbox `identifier` in content';
        }

        // Fallback: infer from content shape + enabled Hyper types on the field.
        $inferred = $this->_inferLinkTypeFromContent($field, $oldSettings);

        if ($inferred) {
            $this->stdout(
                '    > Resolved link type via content fallback (`' . $inferred[1] . '`) after migrationData miss.',
                Console::FG_YELLOW,
            );
            $this->_lastConvertFailureDetail = '';

            return $inferred;
        }

        return null;
    }

    private function _inferLinkTypeFromContent(HyperField $field, array $oldSettings): ?array
    {
        $preferredClass = null;

        if (!empty($oldSettings['email'])) {
            $preferredClass = linkTypes\Email::class;
        } elseif (!empty($oldSettings['url'])) {
            $preferredClass = linkTypes\Url::class;
        } elseif (!empty($oldSettings['elementId'])) {
            // Element targets — prefer Entry when present, else first element link type.
            $preferredClass = linkTypes\Entry::class;
        } else {
            return null;
        }

        foreach ($field->getLinkTypes() as $linkType) {
            if (!$linkType->enabled) {
                continue;
            }

            if ($linkType::class === $preferredClass) {
                return [$linkType::class, (string)$linkType->handle];
            }
        }

        if ($preferredClass === linkTypes\Entry::class) {
            foreach ($field->getLinkTypes() as $linkType) {
                if ($linkType->enabled && $linkType instanceof \verbb\hyper\base\ElementLink) {
                    return [$linkType::class, (string)$linkType->handle];
                }
            }
        }

        return null;
    }

    private function _looksLikeHyperCollection(array $oldSettings): bool
    {
        $first = $oldSettings[0] ?? null;

        if (!is_array($first)) {
            return false;
        }

        if (!empty($first['linkTypeHandle'])) {
            return true;
        }

        $type = (string)($first['type'] ?? '');

        return str_contains($type, 'verbb\\hyper');
    }
}
