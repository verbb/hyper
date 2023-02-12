<?php
namespace verbb\hyper\controllers;

use verbb\hyper\Hyper;
use verbb\hyper\models\Settings;

use craft\web\Controller;

use yii\web\Response;

class PluginController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionSettings(): Response
    {
        /* @var Settings $settings */
        $settings = Hyper::$plugin->getSettings();

        return $this->renderTemplate('hyper/settings', [
            'settings' => $settings,
        ]);
    }
}
