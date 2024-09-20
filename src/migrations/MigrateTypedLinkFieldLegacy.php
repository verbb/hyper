<?php
namespace verbb\hyper\migrations;

use verbb\hyper\console\controllers\TypedLinkLegacyController;

use craft\helpers\Console;

class MigrateTypedLinkFieldLegacy extends PluginMigration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        TypedLinkLegacyController::updateAllSettings();

        $this->stdout('Typed Link fields (legacy) have been updated.' . PHP_EOL, Console::FG_GREEN);

        return true;
    }

}
