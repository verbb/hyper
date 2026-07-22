<?php
namespace verbb\hyper\migrations;

use craft\db\Migration;

class m250703_030000_drop_hyper_field_cache_table extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        $this->dropTableIfExists('{{%hyper_field_cache}}');

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
