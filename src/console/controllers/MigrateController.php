<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateLinkField;
use verbb\hyper\migrations\MigrateLinkContent;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;

use yii\console\ExitCode;

/**
 * Manages Hyper migrations from other plugins.
 */
class MigrateController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Migrate Link It fields to Hyper fields.
     */
    public function actionLinkitField(): int
    {
        return $this->_migrate(MigrateLinkitField::class);
    }

    /**
     * Migrate Link It field content to Hyper.
     */
    public function actionLinkitContent(): int
    {
        return $this->_migrate(MigrateLinkitContent::class);
    }

    /**
     * Migrate Typed Link fields to Hyper fields.
     */
    public function actionTypedLinkField(): int
    {
        return $this->_migrate(MigrateTypedLinkField::class);
    }

    /**
     * Migrate Typed Link field content to Hyper.
     */
    public function actionTypedLinkContent(): int
    {
        return $this->_migrate(MigrateTypedLinkContent::class);
    }

    /**
     * Migrate Link fields to Hyper fields.
     */
    public function actionLinkField(): int
    {
        return $this->_migrate(MigrateLinkField::class);
    }

    /**
     * Migrate Link field content to Hyper.
     */
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
