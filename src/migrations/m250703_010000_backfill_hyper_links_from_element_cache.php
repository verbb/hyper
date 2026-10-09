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
        // content before the following migrations drop the old tables, so a failed
        // rebuild still leaves them in place, without resaving author content.
        (new m260912_000000_rebuild_link_relations_from_content())->safeUp();

        // The rebuild migration is also pending on this upgrade; let it skip the
        // identical second pass, which doubled the upgrade time on large sites.
        m260912_000000_rebuild_link_relations_from_content::$rebuiltByBackfill = true;

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
