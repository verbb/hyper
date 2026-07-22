<?php
namespace verbb\hyper\variables;

use verbb\hyper\Hyper;

use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\UrlHelper;

class HyperVariable
{
    // Public Methods
    // =========================================================================

    public function getPlugin(): Hyper
    {
        return Hyper::$plugin;
    }

    public function getSettingsNavItems(): array
    {
        $items = [
            'general' => [
                'title' => Craft::t('hyper', 'General Settings'),
                'url' => UrlHelper::cpUrl('hyper/settings'),
            ],
            'linkTypeConfigs' => [
                'title' => Craft::t('hyper', 'Link Type Configs'),
                'url' => UrlHelper::cpUrl('hyper/settings/link-type-configs'),
            ],
            'migrationsHeading' => [
                'heading' => Craft::t('hyper', 'Migrations'),
            ],
        ];

        foreach (Hyper::$plugin->getMigrations()->getSources() as $sourceId => $source) {
            if (!($source['showInNav'] ?? false)) {
                continue;
            }

            $items['migrate-' . $sourceId] = [
                'title' => $source['label'],
                'url' => UrlHelper::cpUrl('hyper/settings/migrate/' . $sourceId),
            ];
        }

        return $items;
    }

    public function getRelatedElements(array $params = []): ?ElementQueryInterface
    {
        return Hyper::$plugin->getService()->getRelatedElementsQuery($params);
    }
    
}