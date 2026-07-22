<?php
namespace verbb\hyper\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%hyper_links}}')) {
            return true;
        }

        $this->createTable('{{%hyper_links}}', [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer()->notNull(),
            'ownerId' => $this->integer()->notNull(),
            'ownerSiteId' => $this->integer()->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'linkTypeHandle' => $this->string()->notNull(),
            'targetId' => $this->integer()->null(),
            'targetSiteId' => $this->integer()->null(),
            'targetType' => $this->string()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%hyper_links}}', ['ownerId', 'ownerSiteId', 'fieldId'], false);
        $this->createIndex(null, '{{%hyper_links}}', ['targetId', 'targetSiteId'], false);
        $this->createIndex(null, '{{%hyper_links}}', ['fieldId'], false);

        $this->addForeignKey(null, '{{%hyper_links}}', ['fieldId'], Table::FIELDS, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_links}}', ['ownerId'], Table::ELEMENTS, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_links}}', ['ownerSiteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_links}}', ['targetId'], Table::ELEMENTS, ['id'], 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_links}}', ['targetSiteId'], Table::SITES, ['id'], 'SET NULL', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%hyper_links}}');

        return true;
    }
}
