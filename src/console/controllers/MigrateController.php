<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateLinkField;
use verbb\hyper\migrations\MigrateLinkContent;

use Craft;
use craft\helpers\App;

use yii\console\Controller;
use yii\console\ExitCode;

class MigrateController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLinkitField(): int
    {
        return $this->_migrate(MigrateLinkitField::class);
    }

    public function actionLinkitContent(): int
    {
        return $this->_migrate(MigrateLinkitContent::class);
    }

    public function actionTypedLinkField(): int
    {
        return $this->_migrate(MigrateTypedLinkField::class);
    }

    public function actionTypedLinkContent(): int
    {
        return $this->_migrate(MigrateTypedLinkContent::class);
    }

    public function actionLinkField(): int
    {
        return $this->_migrate(MigrateLinkField::class);
    }

    public function actionLinkContent(): int
    {
        return $this->_migrate(MigrateLinkContent::class);
    }


    // Private Methods
    // =========================================================================

    private function _migrate(string $migrationClass): int
    {
        App::maxPowerCaptain();

        Craft::$app->getDb()->backup();

        $response = [];

        $migration = new $migrationClass();
        $migration->setConsoleRequest($this);
        $migration->up();

        return ExitCode::OK;
    }
}
