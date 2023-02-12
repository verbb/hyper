<?php
namespace verbb\hyper\helpers;

use verbb\hyper\Hyper;
use verbb\hyper\web\assets\field\HyperAsset;

class Plugin
{
    // Static Methods
    // =========================================================================

    public static function registerAsset(string $path): void
    {
        $viteService = Hyper::$plugin->getVite();

        $scriptOptions = [
            'depends' => [
                HyperAsset::class,
            ],
            'onload' => null,
        ];

        $styleOptions = [
            'depends' => [
                HyperAsset::class,
            ],
        ];

        $viteService->register($path, false, $scriptOptions, $styleOptions);

        // Provide nice build errors - only in dev
        if ($viteService->devServerRunning()) {
            $viteService->register('@vite/client', false);
        }
    }

}
