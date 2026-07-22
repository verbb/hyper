<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;

use craft\db\Migration;

class m250703_010000_backfill_hyper_links_from_element_cache extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%hyper_links}}') || !$this->db->tableExists('{{%hyper_element_cache}}')) {
            return true;
        }

        Hyper::$plugin->getLinkRelations()->backfillFromElementCache();

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
