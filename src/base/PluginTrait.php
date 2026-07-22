<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\services\Cache;
use verbb\hyper\services\Content;
use verbb\hyper\services\LinkRelations;
use verbb\hyper\services\LinkedElementEagerLoader;
use verbb\hyper\services\Links;
use verbb\hyper\services\LinkTypeConfigs;
use verbb\hyper\services\Migrations;
use verbb\hyper\services\MultisiteLinks;
use verbb\hyper\services\Service;
use verbb\hyper\web\assets\field\HyperAsset;
use verbb\hyper\migrations\plugins\PluginMigrator;

use verbb\base\LogTrait;
use verbb\base\helpers\Plugin;

use nystudio107\pluginvite\services\VitePluginService;

trait PluginTrait
{
    // Properties
    // =========================================================================

    public static ?Hyper $plugin = null;


    // Traits
    // =========================================================================

    use LogTrait;
    

    // Static Methods
    // =========================================================================

    public static function config(): array
    {
        Plugin::bootstrapPlugin('hyper');

        return [
            'components' => [
                'cache' => Cache::class,
                'content' => Content::class,
                'linkRelations' => LinkRelations::class,
                'linkedElementEagerLoader' => LinkedElementEagerLoader::class,
                'links' => Links::class,
                'linkTypeConfigs' => LinkTypeConfigs::class,
                'migrations' => Migrations::class,
                'multisiteLinks' => MultisiteLinks::class,
                'service' => Service::class,
                'vite' => [
                    'class' => VitePluginService::class,
                    'assetClass' => HyperAsset::class,
                    'useDevServer' => true,
                    'devServerPublic' => 'http://localhost:4010/',
                    'errorEntry' => 'field/src/js/hyper.ts',
                    'cacheKeySuffix' => '',
                    'devServerInternal' => 'http://localhost:4010/',
                    'checkDevServer' => true,
                    'includeReactRefreshShim' => false,
                ],
            ],
        ];
    }


    // Public Methods
    // =========================================================================

    public function getCache(): Cache
    {
        return $this->get('cache');
    }

    public function getContent(): Content
    {
        return $this->get('content');
    }

    public function getLinkRelations(): LinkRelations
    {
        return $this->get('linkRelations');
    }

    public function getLinkedElementEagerLoader(): LinkedElementEagerLoader
    {
        return $this->get('linkedElementEagerLoader');
    }

    public function getLinks(): Links
    {
        return $this->get('links');
    }

    public function getLinkTypeConfigs(): LinkTypeConfigs
    {
        return $this->get('linkTypeConfigs');
    }

    public function getMigrations(): Migrations
    {
        return $this->get('migrations');
    }

    public function createMigrator(string $migrationClass, array $config = []): PluginMigrator
    {
        return $this->getMigrations()->createMigrator($migrationClass, $config);
    }

    public function getMultisiteLinks(): MultisiteLinks
    {
        return $this->get('multisiteLinks');
    }

    public function getService(): Service
    {
        return $this->get('service');
    }

    public function getVite(): VitePluginService
    {
        return $this->get('vite');
    }

}