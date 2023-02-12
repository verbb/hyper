<?php
namespace verbb\hyper\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\MigrationHelper;

class Install extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropForeignKeys();
        $this->removeTables();

        return true;
    }

    public function createTables(): void
    {
        $this->archiveTableIfExists('{{%hyper_element_cache}}');
        $this->createTable('{{%hyper_element_cache}}', [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer(),
            'sourceId' => $this->integer(),
            'sourceSiteId' => $this->integer(),
            'sourceType' => $this->string(255),
            'targetId' => $this->integer(),
            'targetSiteId' => $this->integer(),
            'targetType' => $this->string(255),
            'title' => $this->string(255),
            'uri' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists('{{%hyper_field_cache}}');
        $this->createTable('{{%hyper_field_cache}}', [
            'id' => $this->primaryKey(),
            'sourceField' => $this->uid(),
            'targetField' => $this->uid(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    public function createIndexes(): void
    {
        $this->createIndex(null, '{{%hyper_element_cache}}', 'id', false);
        $this->createIndex(null, '{{%hyper_element_cache}}', 'sourceId', false);
        $this->createIndex(null, '{{%hyper_element_cache}}', 'sourceSiteId', false);
        $this->createIndex(null, '{{%hyper_element_cache}}', 'targetId', false);
        $this->createIndex(null, '{{%hyper_element_cache}}', 'targetSiteId', false);

        $this->createIndex(null, '{{%hyper_field_cache}}', 'id', false);
        $this->createIndex(null, '{{%hyper_field_cache}}', 'sourceField', false);
        $this->createIndex(null, '{{%hyper_field_cache}}', 'targetField', false);
    }

    public function addForeignKeys(): void
    {
        $this->addForeignKey(null, '{{%hyper_element_cache}}', ['sourceId'], Table::ELEMENTS, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_element_cache}}', ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_element_cache}}', ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%hyper_element_cache}}', ['targetSiteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
    }

    public function removeTables(): void
    {
        $this->dropTableIfExists('{{%hyper_element_cache}}');
        $this->dropTableIfExists('{{%hyper_field_cache}}');
    }

    public function dropForeignKeys(): void
    {
        if ($this->db->tableExists('{{%hyper_element_cache}}')) {
            MigrationHelper::dropAllForeignKeysOnTable('{{%hyper_element_cache}}', $this);
        }
    }
}
