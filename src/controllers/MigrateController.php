<?php
namespace verbb\hyper\controllers;

use verbb\hyper\Hyper;
use verbb\hyper\migrations\plugins\Line;
use verbb\hyper\migrations\plugins\MigrationResult;

use Craft;
use craft\helpers\App;
use craft\web\Controller;

use yii\web\NotFoundHttpException;
use yii\web\Response;

use Throwable;

class MigrateController extends Controller
{
    // Constants
    // =========================================================================

    private const SESSION_FLASH_OUTPUT = 'hyper.migrationOutput';


    // Properties
    // =========================================================================

    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;


    // Public Methods
    // =========================================================================

    public function actionIndex(string $sourceId): Response
    {
        $this->requireAdmin(false);

        $source = $this->_requireSource($sourceId, requireReady: false);

        $variables = [
            'sourceId' => $sourceId,
            'source' => $source,
            'selectedNavItem' => 'migrate-' . $sourceId,
            'settings' => Hyper::$plugin->getSettings(),
        ];

        $output = Craft::$app->getSession()->getFlash(self::SESSION_FLASH_OUTPUT);

        if (is_string($output) && $output !== '') {
            $variables['output'] = $output;
        }

        return $this->renderTemplate('hyper/settings/migrate/index', $variables);
    }

    public function actionRun(?string $sourceId = null, ?string $stepId = null): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        App::maxPowerCaptain();

        $sourceId = $sourceId ?? (string)$this->request->getRequiredBodyParam('sourceId');
        $stepId = $stepId ?? (string)$this->request->getRequiredBodyParam('stepId');

        $this->_requireSource($sourceId, requireReady: false);

        $migrationClass = Hyper::$plugin->getMigrations()->getStepMigrationClass($sourceId, $stepId);

        if (!$migrationClass) {
            throw new NotFoundHttpException('Migration step not found.');
        }

        $createBackup = (bool)$this->request->getBodyParam('createBackup', Hyper::$plugin->getSettings()->backupOnMigrate);
        $dryRun = (bool)$this->request->getBodyParam('dryRun');
        $syncRelations = $this->request->getBodyParam('syncRelations');
        $syncRelations = $syncRelations === null ? true : (bool)$syncRelations;

        if ($createBackup && !$dryRun) {
            try {
                Craft::$app->getDb()->backup();
            } catch (Throwable $e) {
                return $this->asFailure(Craft::t('hyper', 'Database backup failed: {message}', [
                    'message' => $e->getMessage(),
                ]));
            }
        }

        $migrator = Hyper::$plugin->createMigrator($migrationClass, [
            'dryRun' => $dryRun,
            'syncRelations' => $syncRelations,
        ]);

        try {
            $result = $migrator->run();
            $html = MigrationResult::renderLinesHtml($result->lines);
            $ok = $result->ok;
        } catch (Throwable $e) {
            $html = MigrationResult::renderLinesHtml([
                Line::error('Failed to migrate: ' . $e->getMessage()),
            ]);
            $ok = false;
        }

        Craft::$app->getSession()->setFlash(self::SESSION_FLASH_OUTPUT, $html);

        if ($ok) {
            $this->setSuccessFlash(Craft::t('hyper', 'Migration completed.'));
        } else {
            $this->setFailFlash(Craft::t('hyper', 'Migration completed with errors.'));
        }

        return $this->redirectToPostedUrl();
    }


    // Private Methods
    // =========================================================================

    private function _requireSource(string $sourceId, bool $requireReady = true): array
    {
        $source = Hyper::$plugin->getMigrations()->getSource($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Migration source not found.');
        }

        if ($requireReady && !($source['ready'] ?? false) && !($source['showInNav'] ?? false)) {
            throw new NotFoundHttpException('Migration source not found.');
        }

        return $source;
    }
}
