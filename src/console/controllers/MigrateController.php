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
    // Properties
    // =========================================================================

    /**
     * @var bool Whether to create a backup before running migration tasks.
     */
    public ?bool $createBackup = null;


    // Public Methods
    // =========================================================================

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        $options[] = 'createBackup';

        return $options;
    }

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

        $createBackup = $this->createBackup ?? Hyper::$plugin->getSettings()->backupOnMigrate;

        if ($createBackup) {
            Craft::$app->getDb()->backup();
        }

        $response = [];

        $migration = new $migrationClass();
        $migration->setConsoleRequest($this);
        $migration->up();

        return ExitCode::OK;
    }
}
