<?php
namespace verbb\hyper\controllers;

use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateLinkField;
use verbb\hyper\migrations\MigrateLinkContent;

use Craft;
use craft\helpers\App;
use craft\web\Controller;

use Throwable;

class MigrationsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLinkitField(): void
    {
        $this->_migrate(MigrateLinkitField::class);
    }

    public function actionLinkitContent(): void
    {
        $this->_migrate(MigrateLinkitContent::class);
    }

    public function actionTypedLinkField(): void
    {
        $this->_migrate(MigrateTypedLinkField::class);
    }

    public function actionTypedLinkContent(): void
    {
        $this->_migrate(MigrateTypedLinkContent::class);
    }

    public function actionLinkField(): void
    {
        $this->_migrate(MigrateLinkField::class);
    }

    public function actionLinkContent(): void
    {
        $this->_migrate(MigrateLinkContent::class);
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
