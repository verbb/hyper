<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\records\LinkRelation;

use Craft;
use craft\db\Query;
use craft\db\Table;

use yii\db\Connection;

class LegacyContentRelationRebuilder
{
    // Properties
    // =========================================================================

    private Connection $_db;


    // Public Methods
    // =========================================================================

    public function __construct(?Connection $db = null)
    {
        $this->_db = $db ?? Craft::$app->getDb();
    }

    /**
     * Index Craft 4 field columns that a host plugin has not converted yet.
     *
     * Craft migrates its own element content before plugin migrations run. Neo
     * and Super Table retain their nested rows in legacy content tables until
     * their migrations execute, but preserve the final owner and field IDs.
     */
    public function rebuild(HyperField $field): int
    {
        if (!$field->id) {
            return 0;
        }

        $column = sprintf(
            'field_%s%s',
            $field->handle,
            $field->columnSuffix ? '_' . $field->columnSuffix : '',
        );
        $synced = 0;
        [$ownTable, $superTableTables] = $this->_superTableContentTables($field);

        foreach ($this->_db->getSchema()->getTableSchemas() as $tableSchema) {
            // Fields created before Craft 3.7 have no column suffix, so the same
            // column name can exist in several content tables. A Super Table field
            // reads only its own table, and other fields skip Super Table tables.
            if ($ownTable !== null
                ? $tableSchema->name !== $ownTable
                : isset($superTableTables[$tableSchema->name])) {
                continue;
            }

            if (!$tableSchema->getColumn('id')
                || !$tableSchema->getColumn('elementId')
                || !$tableSchema->getColumn('siteId')
                || !$tableSchema->getColumn($column)) {
                continue;
            }

            $query = (new Query())
                ->select([
                    'elementId',
                    'siteId',
                    'value' => $column,
                ])
                ->from($tableSchema->fullName)
                ->where(['not', [$column => null]])
                ->andWhere(['<>', $column, '']);

            foreach ($query->each(db: $this->_db) as $row) {
                $ownerId = (int)$row['elementId'];
                $ownerSiteId = (int)$row['siteId'];

                if (!$this->_isIndexableOwner($ownerId, $ownerSiteId)) {
                    continue;
                }

                $value = ElementContentStore::decodeStored($row['value']);

                if (!is_array($value)) {
                    continue;
                }

                $collection = new LinkCollection($field, $value, ownerSiteId: $ownerSiteId);
                $this->_syncCollection($field, $ownerId, $ownerSiteId, $collection);
                $synced++;
            }
        }

        return $synced;
    }


    // Private Methods
    // =========================================================================

    /**
     * Resolve the Super Table content tables that remain before its own migration.
     *
     * Returns this field's table when it belongs to a Super Table block type (an
     * empty string when unresolved, so nothing is guessed), and every Super Table
     * content table so fields in other contexts can skip them.
     */
    private function _superTableContentTables(HyperField $field): array
    {
        $blockTypesTable = '{{%supertableblocktypes}}';

        if (!$this->_db->tableExists($blockTypesTable)) {
            return [null, []];
        }

        $schema = $this->_db->getSchema();
        $tables = [];
        $tablesByBlockType = [];
        $rows = (new Query())
            ->select(['blockTypes.uid', 'fields.settings'])
            ->from(['blockTypes' => $blockTypesTable])
            ->innerJoin(['fields' => Table::FIELDS], '[[fields.id]] = [[blockTypes.fieldId]]')
            ->all($this->_db);

        foreach ($rows as $row) {
            $settings = is_string($row['settings']) ? json_decode($row['settings'], true) : $row['settings'];
            $contentTable = is_array($settings) ? ($settings['contentTable'] ?? null) : null;

            if (!is_string($contentTable) || $contentTable === '') {
                continue;
            }

            $name = $schema->getRawTableName($contentTable);
            $tables[$name] = true;
            $tablesByBlockType[$row['uid']] = $name;
        }

        $ownTable = null;
        $prefix = 'superTableBlockType:';

        if (is_string($field->context) && str_starts_with($field->context, $prefix)) {
            $ownTable = $tablesByBlockType[substr($field->context, strlen($prefix))] ?? '';
        }

        return [$ownTable, $tables];
    }

    private function _isIndexableOwner(int $ownerId, int $ownerSiteId): bool
    {
        $owner = (new Query())
            ->select(['dateDeleted', 'draftId', 'revisionId'])
            ->from(Table::ELEMENTS)
            ->where(['id' => $ownerId])
            ->one($this->_db);

        if (!$owner || $owner['dateDeleted'] || $owner['draftId'] || $owner['revisionId']) {
            return false;
        }

        return (new Query())
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $ownerId, 'siteId' => $ownerSiteId])
            ->exists($this->_db);
    }

    private function _syncCollection(
        HyperField $field,
        int $ownerId,
        int $ownerSiteId,
        LinkCollection $collection,
    ): void {
        $rows = [];

        foreach ($collection->getLinks() as $sortOrder => $link) {
            if (!$link instanceof ElementLink) {
                continue;
            }

            $linkValue = $link->linkValue;
            $targetId = is_array($linkValue)
                ? (int)($linkValue[0] ?? 0)
                : (int)$linkValue;

            if (!$targetId) {
                continue;
            }

            $targetType = $link::elementType();
            $rows[] = [
                'fieldId' => $field->id,
                'ownerId' => $ownerId,
                'ownerSiteId' => $ownerSiteId,
                'sortOrder' => (int)$sortOrder,
                'linkTypeHandle' => (string)($link->handle ?? ''),
                'targetId' => $targetId,
                'targetSiteId' => $link->linkSiteId ? (int)$link->linkSiteId : $ownerSiteId,
                'targetType' => $targetType,
            ];
        }

        if ($rows) {
            $targets = (new Query())
                ->select(['id', 'type'])
                ->from(Table::ELEMENTS)
                ->where(['id' => array_values(array_unique(array_column($rows, 'targetId')))])
                ->indexBy('id')
                ->all($this->_db);
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool =>
                isset($targets[$row['targetId']]) && $targets[$row['targetId']]['type'] === $row['targetType']
            ));
        }

        $this->_db->createCommand()->delete(LinkRelation::tableName(), [
            'fieldId' => $field->id,
            'ownerId' => $ownerId,
            'ownerSiteId' => $ownerSiteId,
        ])->execute();

        foreach (array_chunk($rows, 500) as $batch) {
            $this->_db->createCommand()->batchInsert(
                LinkRelation::tableName(),
                array_keys($batch[0]),
                array_map('array_values', $batch),
            )->execute();
        }
    }
}
