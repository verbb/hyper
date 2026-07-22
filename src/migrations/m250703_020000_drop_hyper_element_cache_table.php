<?php
namespace verbb\hyper\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

class m250703_020000_drop_hyper_element_cache_table extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%hyper_element_cache}}')) {
            return true;
        }

        MigrationHelper::dropAllForeignKeysOnTable('{{%hyper_element_cache}}', $this);
        $this->dropTable('{{%hyper_element_cache}}');

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
