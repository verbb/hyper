<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\Hyper;
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

/**
 * Manages Hyper migrations from other link plugins.
 */
class MigrateController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Migrates LinkIt fields to Hyper links.
     */
    public function actionLinkitField(): int
    {
        return $this->_migrate(MigrateLinkitField::class);
    }

    /**
     * Migrates LinkIt field content to Hyper links.
     */
    public function actionLinkitContent(): int
    {
        return $this->_migrate(MigrateLinkitContent::class);
    }

    /**
     * Migrates Typed Link fields to Hyper links.
     */
    public function actionTypedLinkField(): int
    {
        return $this->_migrate(MigrateTypedLinkField::class);
    }

    /**
     * Migrates Typed Link field content to Hyper links.
     */
    public function actionTypedLinkContent(): int
    {
        return $this->_migrate(MigrateTypedLinkContent::class);
    }

    /**
     * Migrates Link fields to Hyper links.
     */
    public function actionLinkField(): int
    {
        return $this->_migrate(MigrateLinkField::class);
    }

    /**
     * Migrates Link field content to Hyper links.
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

        if (Hyper::$plugin->getSettings()->backupOnMigrate) {
            Craft::$app->getDb()->backup();
        }

        $response = [];

        $migration = new $migrationClass();
        $migration->setConsoleRequest($this);
        $migration->up();

        return ExitCode::OK;
    }
}
