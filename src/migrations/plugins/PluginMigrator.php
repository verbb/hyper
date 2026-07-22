<?php
namespace verbb\hyper\migrations\plugins;

use verbb\hyper\migrations\PluginMigration;

use Craft;

use yii\base\Component;
use yii\console\Controller as ConsoleController;

use Throwable;

class PluginMigrator extends Component
{
    // Properties
    // =========================================================================

    public string $migrationClass;

    public ?ConsoleController $consoleRequest = null;

    public bool $dryRun = false;

    public bool $syncRelations = true;

    protected MigrationResult $result;


    // Public Methods
    // =========================================================================

    public function run(): MigrationResult
    {
        $this->result = new MigrationResult();

        try {
            $migration = Craft::createObject([
                'class' => $this->migrationClass,
            ]);

            if ($this->consoleRequest) {
                $migration->setConsoleRequest($this->consoleRequest);
            }

            $migration->setMigrationResult($this->result);

            if (property_exists($migration, 'dryRun')) {
                $migration->dryRun = $this->dryRun;
            }

            if (property_exists($migration, 'syncRelations')) {
                $migration->syncRelations = $this->syncRelations;
            }

            $ok = $migration->up();

            if ($ok === false) {
                $this->result->ok = false;
            }
        } catch (Throwable $e) {
            $this->result->ok = false;
            $this->result->addLine(Line::error($e->getMessage()));
            Craft::error($e->getMessage() . PHP_EOL . $e->getTraceAsString(), __METHOD__);
        }

        return $this->result;
    }
}
