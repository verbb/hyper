<?php
namespace verbb\hyper\migrations;

use craft\db\Migration;
use craft\db\Table;

class m261017_000000_create_draft_baselines extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%hyper_draft_baselines}}')) {
            $this->createTable('{{%hyper_draft_baselines}}', [
                'draftId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'fieldId' => $this->integer()->notNull(),
                'layoutUid' => $this->uid(),
                'baseline' => $this->string(64),
                'edited' => $this->boolean()->notNull()->defaultValue(false),
                'PRIMARY KEY ([[draftId]], [[siteId]], [[layoutUid]])',
            ]);
            $this->addForeignKey(null, '{{%hyper_draft_baselines}}', ['draftId'], Table::ELEMENTS, ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%hyper_draft_baselines}}', ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%hyper_draft_baselines}}', ['fieldId'], Table::FIELDS, ['id'], 'CASCADE', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%hyper_draft_baselines}}');
        return true;
    }
}
