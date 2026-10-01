<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;

use craft\db\Migration;

class m261001_000000_default_link_type_config extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        Hyper::$plugin->getLinkTypeConfigs()->ensureConfigsExist();

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
