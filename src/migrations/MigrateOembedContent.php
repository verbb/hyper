<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;
use verbb\hyper\links\Embed;

use craft\helpers\Console;
use craft\helpers\Json;
use craft\helpers\StringHelper;

class MigrateOembedContent extends PluginContentMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [];

    public string $oldFieldTypeClass = 'wrav\\oembed\\fields\\OembedField';


    // Public Methods
    // =========================================================================

    public function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        if (array_is_list($oldSettings) && isset($oldSettings[0]) && is_array($oldSettings[0])) {
            $hyperType = $oldSettings[0]['type'] ?? null;
            $handle = $oldSettings[0]['linkTypeHandle'] ?? $oldSettings[0]['handle'] ?? null;

            if ($handle || (is_string($hyperType) && str_contains($hyperType, 'verbb\\hyper'))) {
                $this->stdout('    > Content already migrated to Hyper content.', Console::FG_GREEN);

                return null;
            }
        }

        $url = $this->_extractUrl($oldSettings);

        if ($url === null || $url === '') {
            return null;
        }

        $handle = Embed::typeKey();

        foreach ($field->getLinkTypes() as $configured) {
            if ($configured instanceof Embed && $configured->enabled) {
                $handle = $configured->handle;
                break;
            }
        }

        $link = new Embed();
        $link->handle = $handle;
        $link->field = $field;

        // Persist URL; Embed::setAttributes fetches metadata when given a string
        $link->setAttributes(['linkValue' => $url], false);

        if (!$link->getLinkUrl()) {
            $link->linkValue = ['url' => $url];
        }

        return $this->serializeMigratedLink($link);
    }


    // Private Methods
    // =========================================================================

    private function _extractUrl(array $oldSettings): ?string
    {
        // Plain string stored under a synthetic key from Content::modify raw decode edge cases
        if (isset($oldSettings['url']) && is_string($oldSettings['url'])) {
            return trim($oldSettings['url']);
        }

        // Scalar-only payload wrapped oddly
        if (count($oldSettings) === 1 && isset($oldSettings[0]) && is_string($oldSettings[0])) {
            return trim($oldSettings[0]);
        }

        return null;
    }
}
