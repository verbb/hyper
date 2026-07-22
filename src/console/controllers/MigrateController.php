<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\helpers\MigrationRenderer;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\MigrateEntrifyCategories;
use verbb\hyper\migrations\MigrateLinkContent;
use verbb\hyper\migrations\MigrateLinkField;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateTypedLinkField;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;

use yii\console\ExitCode;

/**
 * Migrates fields and content from third-party / native link plugins into Hyper.
 *
 * Prefer source actions (`typed-link`, `linkit`, `link`, `craft-link`, `oembed`) with `--step=`.
 * Legacy per-class actions remain for backwards compatibility.
 */
class MigrateController extends Controller
{
    // Properties
    // =========================================================================

    public ?bool $createBackup = null;
    public string $step = 'all';
    public bool $dryRun = false;
    public bool $syncRelations = true;


    // Public Methods
    // =========================================================================

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'createBackup';
        $options[] = 'step';
        $options[] = 'dryRun';
        $options[] = 'syncRelations';

        return $options;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'dry-run' => 'dryRun',
            'sync-relations' => 'syncRelations',
            'create-backup' => 'createBackup',
        ]);
    }

    public function actionTypedLink(): int
    {
        return $this->_runSource('typed-link');
    }

    public function actionLinkit(): int
    {
        return $this->_runSource('linkit');
    }

    public function actionLink(): int
    {
        return $this->_runSource('link');
    }

    public function actionCraftLink(): int
    {
        return $this->_runSource('craft-link');
    }

    public function actionOembed(): int
    {
        return $this->_runSource('oembed');
    }

    public function actionEntrifyCategories(): int
    {
        $this->stdout('Remapping Category links → Entry (same element IDs)…' . PHP_EOL, Console::FG_GREEN);

        return $this->_migrateClass(MigrateEntrifyCategories::class)
            ? ExitCode::OK
            : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionTypedLinkField(): int
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::actionTypedLinkField', 'The `hyper/migrate/typed-link-field` console command has been deprecated. Use `hyper/migrate/typed-link --step=field` instead.');

        return $this->_migrateClass(MigrateTypedLinkField::class) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionTypedLinkContent(): int
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::actionTypedLinkContent', 'The `hyper/migrate/typed-link-content` console command has been deprecated. Use `hyper/migrate/typed-link --step=content` instead.');

        return $this->_migrateClass(MigrateTypedLinkContent::class) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionLinkitField(): int
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::actionLinkitField', 'The `hyper/migrate/linkit-field` console command has been deprecated. Use `hyper/migrate/linkit --step=field` instead.');

        return $this->_migrateClass(MigrateLinkitField::class) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionLinkitContent(): int
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::actionLinkitContent', 'The `hyper/migrate/linkit-content` console command has been deprecated. Use `hyper/migrate/linkit --step=content` instead.');

        return $this->_migrateClass(MigrateLinkitContent::class) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionLinkField(): int
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::actionLinkField', 'The `hyper/migrate/link-field` console command has been deprecated. Use `hyper/migrate/link --step=field` instead.');

        return $this->_migrateClass(MigrateLinkField::class) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionLinkContent(): int
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::actionLinkContent', 'The `hyper/migrate/link-content` console command has been deprecated. Use `hyper/migrate/link --step=content` instead.');

        return $this->_migrateClass(MigrateLinkContent::class) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }


    // Private Methods
    // =========================================================================

    private function _runSource(string $sourceId): int
    {
        $source = Hyper::$plugin->getMigrations()->getSource($sourceId);

        if (!$source) {
            $this->stderr("Unknown migration source “{$sourceId}”." . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $steps = $source['steps'];
        $wanted = $this->step === 'all'
            ? array_column($steps, 'id')
            : [$this->step];

        $ok = true;
        $ran = 0;

        foreach ($steps as $step) {
            if (!in_array($step['id'], $wanted, true)) {
                continue;
            }

            $ran++;
            $this->stdout('Running ' . $source['label'] . ' → ' . $step['label'] . '…' . PHP_EOL, Console::FG_GREEN);

            if (!$this->_migrateClass($step['migrationClass'])) {
                $ok = false;
            }
        }

        if ($ran === 0) {
            $this->stderr("No matching step “{$this->step}” for source “{$sourceId}”." . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function _migrateClass(string $migrationClass): bool
    {
        App::maxPowerCaptain();

        $createBackup = $this->createBackup ?? Hyper::$plugin->getSettings()->backupOnMigrate;

        if ($createBackup && !$this->dryRun) {
            Craft::$app->getDb()->backup();
        }

        $migrator = Hyper::$plugin->createMigrator($migrationClass, [
            'consoleRequest' => $this,
            'dryRun' => $this->dryRun,
            'syncRelations' => $this->syncRelations,
        ]);

        $result = $migrator->run();
        MigrationRenderer::renderResultToConsole($result);

        return $result->ok;
    }
}
