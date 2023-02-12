<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\services\Content;
use verbb\hyper\services\ElementCache;
use verbb\hyper\services\FieldCache;
use verbb\hyper\services\Links;
use verbb\hyper\services\Service;
use verbb\hyper\web\assets\field\HyperAsset;

use Craft;

use yii\log\Logger;

use verbb\base\BaseHelper;

use nystudio107\pluginvite\services\VitePluginService;

trait PluginTrait
{
    // Static Properties
    // =========================================================================

    public static Hyper $plugin;


    // Public Methods
    // =========================================================================

    public static function log($message, $attributes = []): void
    {
        if ($attributes) {
            $message = Craft::t('hyper', $message, $attributes);
        }

        Craft::getLogger()->log($message, Logger::LEVEL_INFO, 'hyper');
    }

    public static function error($message, $attributes = []): void
    {
        if ($attributes) {
            $message = Craft::t('hyper', $message, $attributes);
        }

        Craft::getLogger()->log($message, Logger::LEVEL_ERROR, 'hyper');
    }


    // Public Methods
    // =========================================================================

    public function getContent(): Content
    {
        return $this->get('content');
    }

    public function getElementCache(): ElementCache
    {
        return $this->get('elementCache');
    }

    public function getFieldCache(): FieldCache
    {
        return $this->get('fieldCache');
    }

    public function getLinks(): Links
    {
        return $this->get('links');
    }

    public function getService(): Service
    {
        return $this->get('service');
    }

    public function getVite(): VitePluginService
    {
        return $this->get('vite');
    }


    // Private Methods
    // =========================================================================

    private function _setPluginComponents(): void
    {
        $this->setComponents([
            'content' => Content::class,
            'elementCache' => ElementCache::class,
            'fieldCache' => FieldCache::class,
            'links' => Links::class,
            'service' => Service::class,
            'vite' => [
                'class' => VitePluginService::class,
                'assetClass' => HyperAsset::class,
                'useDevServer' => true,
                'devServerPublic' => 'http://localhost:4010/',
                'errorEntry' => 'js/main.js',
                'cacheKeySuffix' => '',
                'devServerInternal' => 'http://localhost:4010/',
                'checkDevServer' => true,
                'includeReactRefreshShim' => false,
            ],
        ]);

        BaseHelper::registerModule();
    }

    private function _setLogging(): void
    {
        BaseHelper::setFileLogging('hyper');
    }

}