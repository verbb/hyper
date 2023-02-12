<?php
namespace verbb\hyper\controllers;

use verbb\hyper\migrations\MigrateLinkit;
use verbb\hyper\migrations\MigrateTypedLink;
use verbb\hyper\migrations\MigrateLink;

use Craft;
use craft\helpers\App;
use craft\web\Controller;

use Throwable;

class MigrationsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLinkit(): void
    {
        $this->_migrate(MigrateLinkit::class);
    }

    public function actionTypedLink(): void
    {
        $this->_migrate(MigrateTypedLink::class);
    }

    public function actionLink(): void
    {
        $this->_migrate(MigrateLink::class);
    }


    // Private Methods
    // =========================================================================

    private function _migrate(string $migrationClass): void
    {
        App::maxPowerCaptain();

        Craft::$app->getDb()->backup();

        $response = [];

        $migration = new $migrationClass();

        try {
            ob_start();
            $migration->up();
            $output = ob_get_clean();

            $response[] = nl2br($output);
        } catch (Throwable $e) {
            $response[] = 'Failed to migrate: ' . $e->getMessage();
        }

        Craft::$app->getUrlManager()->setRouteParams([
            'response' => $response,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('hyper', 'Links migrated.'));
    }
}
