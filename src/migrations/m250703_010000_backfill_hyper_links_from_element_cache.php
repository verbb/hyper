<?php
namespace verbb\hyper\migrations;

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

        // Cache rows can be missing, stale or deduplicated. Rebuild from owner
        // content before dropping the old tables, without resaving author content.
        (new m260912_000000_rebuild_link_relations_from_content())->safeUp();

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
