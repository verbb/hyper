<?php

use craft\db\Migration;
use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\migrations\LegacyContentRelationRebuilder;
use verbb\hyper\migrations\m250703_010000_backfill_hyper_links_from_element_cache as Backfill;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\records\LinkRelation;

it('rebuilds relations from legacy nested content before host-plugin conversion', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Legacy target');
    $owner = F::plainEntry($section, 'Legacy nested owner');
    $table = '{{%hyper_test_legacy_content}}';
    $column = sprintf(
        'field_%s%s',
        $field->handle,
        $field->columnSuffix ? '_' . $field->columnSuffix : '',
    );
    $migration = new class extends Migration {
        public function safeUp(): bool
        {
            return true;
        }

        public function safeDown(): bool
        {
            return true;
        }
    };

    $migration->createTable($table, [
        'id' => $migration->primaryKey(),
        'elementId' => $migration->integer()->notNull(),
        'siteId' => $migration->integer()->notNull(),
        $column => $migration->text(),
    ]);
    Craft::$app->db->getSchema()->refresh();

    try {
        Craft::$app->db->createCommand()->insert($table, [
            'elementId' => $owner->id,
            'siteId' => $owner->siteId,
            $column => Json::encode([F::entryLinkPayload($target, 'Legacy nested target')]),
        ])->execute();

        expect((new Rebuild())->safeUp())->toBeTrue();

        $row = LinkRelation::find()->where([
            'fieldId' => $field->id,
            'ownerId' => $owner->id,
            'ownerSiteId' => $owner->siteId,
        ])->one();

        expect($row)->not->toBeNull()
            ->and((int)$row->targetId)->toBe($target->id)
            ->and((int)$row->sortOrder)->toBe(0);
    } finally {
        $migration->dropTableIfExists($table);
        Craft::$app->db->getSchema()->refresh();
    }
});

it('rebuilds relations once when the backfill and rebuild migrations run in the same upgrade', function() {
    LegacyElementCacheSeeder::ensureLegacyTable();
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [F::entryLinkPayload($target, 'Target')]]);
    $count = fn() => (int)(new Query())->from('{{%hyper_links}}')->where(['ownerId' => $owner->id])->count();

    try {
        // The backfill rebuilds before the legacy tables are dropped.
        LinkRelation::deleteAll(['ownerId' => $owner->id]);
        expect((new Backfill())->safeUp())->toBeTrue()
            ->and($count())->toBe(1);

        // The pending rebuild migration in the same process skips the second pass.
        LinkRelation::deleteAll(['ownerId' => $owner->id]);
        expect((new Rebuild())->safeUp())->toBeTrue()
            ->and($count())->toBe(0);

        // The skip is used once; a later run (or a resumed upgrade) rebuilds.
        expect((new Rebuild())->safeUp())->toBeTrue()
            ->and($count())->toBe(1);
    } finally {
        Rebuild::$rebuiltByBackfill = false;
    }
});

it('reads legacy rows only from the content table that owns the field context', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Shared target');
    $globalOwner = F::plainEntry($section, 'Global owner');
    $superTableOwner = F::plainEntry($section, 'Super Table owner');
    $column = sprintf(
        'field_%s%s',
        $field->handle,
        $field->columnSuffix ? '_' . $field->columnSuffix : '',
    );
    $blockTypeUid = craft\helpers\StringHelper::UUID();
    $legacyTable = '{{%hyper_test_legacy_global}}';
    $superTable = '{{%hyper_test_stc_links}}';
    $blockTypesTable = '{{%supertableblocktypes}}';
    $hadBlockTypes = Craft::$app->db->tableExists($blockTypesTable);
    $migration = new class extends Migration {
        public function safeUp(): bool
        {
            return true;
        }

        public function safeDown(): bool
        {
            return true;
        }
    };
    $db = Craft::$app->db;
    $superTableFieldId = null;

    // Before Craft 3.7, fields had no column suffix, so both tables can share a column.
    foreach ([$legacyTable, $superTable] as $table) {
        $migration->createTable($table, [
            'id' => $migration->primaryKey(),
            'elementId' => $migration->integer()->notNull(),
            'siteId' => $migration->integer()->notNull(),
            $column => $migration->text(),
        ]);
    }

    if (!$hadBlockTypes) {
        $migration->createTable($blockTypesTable, [
            'id' => $migration->primaryKey(),
            'fieldId' => $migration->integer()->notNull(),
            'uid' => $migration->uid(),
        ]);
    }

    $db->getSchema()->refresh();

    try {
        $db->createCommand()->insert('{{%fields}}', [
            'name' => 'Legacy Super Table',
            'handle' => F::handle('legacySuperTable'),
            'context' => 'global',
            'type' => 'verbb\\supertable\\fields\\SuperTableField',
            'settings' => Json::encode(['contentTable' => $superTable]),
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();
        $superTableFieldId = (int)$db->getLastInsertID();
        $db->createCommand()->insert($blockTypesTable, [
            'fieldId' => $superTableFieldId,
            'uid' => $blockTypeUid,
        ])->execute();

        foreach ([[$legacyTable, $globalOwner], [$superTable, $superTableOwner]] as [$table, $owner]) {
            $db->createCommand()->insert($table, [
                'elementId' => $owner->id,
                'siteId' => $owner->siteId,
                $column => Json::encode([F::entryLinkPayload($target, 'Legacy')]),
            ])->execute();
        }

        $owners = fn() => array_map('intval', (new Query())
            ->select('ownerId')
            ->from('{{%hyper_links}}')
            ->where(['fieldId' => $field->id, 'ownerId' => [$globalOwner->id, $superTableOwner->id]])
            ->column());

        LinkRelation::deleteAll(['fieldId' => $field->id]);
        (new LegacyContentRelationRebuilder())->rebuild($field);
        expect($owners())->toBe([$globalOwner->id]);

        LinkRelation::deleteAll(['fieldId' => $field->id]);
        $nested = clone $field;
        $nested->context = 'superTableBlockType:' . $blockTypeUid;
        (new LegacyContentRelationRebuilder())->rebuild($nested);
        expect($owners())->toBe([$superTableOwner->id]);
    } finally {
        if ($superTableFieldId) {
            $db->createCommand()->delete('{{%fields}}', ['id' => $superTableFieldId])->execute();
        }

        if (!$hadBlockTypes) {
            $migration->dropTableIfExists($blockTypesTable);
        } else {
            $db->createCommand()->delete($blockTypesTable, ['uid' => $blockTypeUid])->execute();
        }

        $migration->dropTableIfExists($legacyTable);
        $migration->dropTableIfExists($superTable);
        $db->getSchema()->refresh();
    }
});

it('rebuilds an upgraded index from legacy JSON including duplicate and aliased links without resaving content', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $type = $section->getEntryTypes()[0];
    $layout = $type->getFieldLayout();
    $tabs = $layout->getTabs();
    $tabs[0]->setElements([...$tabs[0]->getElements(), new CustomField($field, ['handle' => 'otherLinks'])]);
    $layout->setTabs($tabs);
    $type->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
    $a = F::plainEntry($section, 'A');
    $b = F::plainEntry($section, 'B');
    $owner = F::plainEntry($section, 'Owner');
    $raw = [];
    foreach ([$field->handle, 'otherLinks'] as $handle) {
        $uid = $owner->getFieldLayout()->getFieldByHandle($handle)->layoutElement->uid;
        $raw[$uid] = [
            ['type' => verbb\hyper\links\Url::class, 'handle' => 'url', 'linkValue' => '/leading', 'linkText' => 'URL'],
            ['type' => verbb\hyper\links\Entry::class, 'handle' => 'entry', 'linkValue' => [$a->id], 'linkText' => 'A'],
            ['type' => verbb\hyper\links\Entry::class, 'handle' => 'entry', 'linkValue' => [$b->id], 'linkText' => 'B'],
            ['type' => verbb\hyper\links\Entry::class, 'handle' => 'entry', 'linkValue' => [$a->id], 'linkText' => 'A again'],
        ];
    }
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression($raw)], $where)->execute();
    LinkRelation::deleteAll(['ownerId' => $owner->id]);
    $readContent = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $readRows = fn() => (new Query())->select(['sortOrder','linkTypeHandle','targetId','targetSiteId','targetType'])->from('{{%hyper_links}}')->where(['ownerId' => $owner->id])->orderBy('sortOrder')->all();
    $before = $readContent();
    expect((new Rebuild())->safeUp())->toBeTrue();
    $rows = $readRows();
    expect(array_map('intval', array_column($rows, 'targetId')))->toBe([$a->id,$b->id,$a->id,$a->id,$b->id,$a->id]);
    expect(array_map('intval', array_column($rows, 'sortOrder')))->toBe([1,2,3,5,6,7]);
    expect(array_unique(array_column($rows, 'linkTypeHandle')))->toBe(['entry']);
    expect($readContent())->toBe($before);
    expect((new Rebuild())->safeUp())->toBeTrue();
    expect($readRows())->toBe($rows);
    expect($readContent())->toBe($before);
});

it('preserves trashed owner content while excluding its relations during upgrade', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $owner = F::plainEntry($section, 'Trash', [$field->handle => [F::entryLinkPayload($target, 'Recoverable')]]);
    expect(Craft::$app->elements->deleteElement($owner))->toBeTrue();
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $before = $read();
    expect($before)->not->toBeNull();
    expect((new Rebuild())->safeUp())->toBeTrue();
    expect($read())->toBe($before);
    expect(LinkRelation::find()->where(['ownerId' => $owner->id])->exists())->toBeFalse();
});

it('rebuilds archived owner relations without changing legacy content', function(string $migrationClass) {
    // The backfill runs only when the Hyper 2 cache table exists.
    LegacyElementCacheSeeder::ensureLegacyTable();
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $owner = F::plainEntry($section, 'Archived owner');
    $owner->archived = true;
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();

    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([
        $uid => [F::entryLinkPayload($target, 'Retained archived link')],
    ])], $where)->execute();
    $readContent = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $readTargets = fn() => array_map('intval', (new Query())->select('targetId')->from('{{%hyper_links}}')->where(['ownerId' => $owner->id])->column());
    $before = $readContent();

    try {
        expect(Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: true)))->toBe(1);
        expect($readTargets())->toBe([]);
        expect($readContent())->toBe($before);
        expect((new $migrationClass())->safeUp())->toBeTrue();
        expect($readTargets())->toBe([$target->id]);
        expect($readContent())->toBe($before);
        expect(Hyper::$plugin->content->reconcileRelations($field))->toBe(1);
        expect($readTargets())->toBe([$target->id]);
        expect($readContent())->toBe($before);
    } finally {
        Rebuild::$rebuiltByBackfill = false;
        $owner->archived = false;
        Craft::$app->elements->saveElement($owner);
    }
})->with([Backfill::class, Rebuild::class]);

it('synchronizes relation changes when modifying archived owner content', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $original = F::plainEntry($section, 'Original target');
    $replacement = F::plainEntry($section, 'Replacement target');
    $owner = F::plainEntry($section, 'Archived owner', [$field->handle => [F::entryLinkPayload($original)]]);
    $owner->archived = true;
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();

    try {
        $result = Hyper::$plugin->content->modify($field,
            fn() => new LinkCollection($field, [F::entryLinkPayload($replacement)]),
            new ModifyOptions(elementIds: [$owner->id]),
        );
        expect($result->modified)->toBe(1);
        $saved = Craft::$app->elements->getElementById($owner->id, craft\elements\Entry::class, $owner->siteId, ['archived' => true]);
        expect($saved->getFieldValue($field->handle)->first()->getElement()?->id)->toBe($replacement->id);
        expect(array_map('intval', (new Query())->select('targetId')->from('{{%hyper_links}}')->where(['ownerId' => $owner->id])->column()))->toBe([$replacement->id]);
    } finally {
        $owner->archived = false;
        Craft::$app->elements->saveElement($owner);
    }
});

it('preserves unavailable owner content and resumes indexing when its element type returns', function() {
    LegacyElementCacheSeeder::ensureLegacyTable();
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $available = F::plainEntry($section, 'Available', [$field->handle => [F::entryLinkPayload($target, 'Available')]]);
    $unavailable = F::plainEntry($section, 'Unavailable', [$field->handle => [F::entryLinkPayload($target, 'Preserved')]]);
    $db = Craft::$app->db;
    $readContent = fn() => (new Query())->select(['elementId', 'siteId', 'content'])->from('{{%elements_sites}}')->orderBy('id')->all();
    $readOwners = fn() => array_map('intval', (new Query())->select('ownerId')->from('{{%hyper_links}}')->where(['fieldId' => $field->id])->orderBy('ownerId')->column());
    $before = $readContent();

    // Replay a plugin removed from Composer while its element and content rows remain.
    $db->createCommand()->update('{{%elements}}', ['type' => 'unavailable\\hypertests\\Block'], ['id' => $unavailable->id])->execute();

    try {
        $relationsBefore = $readOwners();
        expect(Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: true)))->toBe(1);
        expect($readOwners())->toBe($relationsBefore);
        expect($readContent())->toBe($before);

        expect((new Backfill())->safeUp())->toBeTrue();
        expect($readOwners())->toBe([$available->id]);
        expect($readContent())->toBe($before);
        expect((new Rebuild())->safeUp())->toBeTrue();
        expect((new Rebuild())->safeUp())->toBeTrue();
        expect($readOwners())->toBe([$available->id]);
        expect($readContent())->toBe($before);

        // Content edits must still fail and roll back when their index owner is unavailable.
        $transform = fn() => new LinkCollection($field, [F::entryLinkPayload($target, 'Changed')]);
        expect(fn() => Hyper::$plugin->content->modify($field, $transform, new ModifyOptions(elementIds: [$unavailable->id])))
            ->toThrow(RuntimeException::class, 'Unable to resolve Hyper relation owner.');
        expect($readContent())->toBe($before);
    } finally {
        Rebuild::$rebuiltByBackfill = false;
        $db->createCommand()->update('{{%elements}}', ['type' => craft\elements\Entry::class], ['id' => $unavailable->id])->execute();
    }

    expect(Hyper::$plugin->content->reconcileRelations($field))->toBe(2);
    expect($readOwners())->toBe([$available->id, $unavailable->id]);
    expect($readContent())->toBe($before);
});

it('identifies unresolved owners without changing content or relations', function(string $operation) {
    LegacyElementCacheSeeder::ensureLegacyTable();
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $owner = F::plainEntry($section, 'Invalid owner', [$field->handle => [F::entryLinkPayload($target, 'Preserved')]]);
    $db = Craft::$app->db;
    $readContent = fn() => (new Query())->from('{{%elements_sites}}')->where(['elementId' => $owner->id, 'siteId' => $owner->siteId])->one();
    $readRelations = fn() => (new Query())->from('{{%hyper_links}}')->where(['ownerId' => $owner->id])->orderBy('id')->all();
    $contentBefore = $readContent();
    $relationsBefore = $readRelations();
    $placement = $owner->getFieldLayout()->getCustomFieldElements()[0]->uid;
    // A loadable type with no corresponding row must not be treated as an absent plugin.
    $db->createCommand()->update('{{%elements}}', ['type' => craft\elements\Asset::class], ['id' => $owner->id])->execute();

    try {
        $run = function() use ($operation, $field, $db) {
            if ($operation === 'backfill' || $operation === 'rebuild') {
                $migration = $operation === 'backfill' ? new Backfill() : new Rebuild();
                // Match Craft's migration transaction while letting the exception reach the assertion.
                return $db->transaction(fn() => $migration->safeUp());
            }

            return Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: $operation === 'dry-run'));
        };
        $message = sprintf(
            'Unable to resolve Hyper relation owner. Element ID: %d; site ID: %d; type: %s; content row ID: %d; path: %s.',
            $owner->id, $owner->siteId, craft\elements\Asset::class, $contentBefore['id'], $placement,
        );
        expect($run)->toThrow(RuntimeException::class, $message);
        expect($readContent())->toBe($contentBefore)
            ->and($readRelations())->toBe($relationsBefore);
    } finally {
        Rebuild::$rebuiltByBackfill = false;
        $db->createCommand()->update('{{%elements}}', ['type' => craft\elements\Entry::class], ['id' => $owner->id])->execute();
    }
})->with(['reconcile', 'dry-run', 'backfill', 'rebuild']);

it('rolls the entire relation rebuild back if a later owner cannot be indexed', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $a = F::plainEntry($section, 'First', [$field->handle => [F::entryLinkPayload($target, 'First')]]);
    $b = F::plainEntry($section, 'Second', [$field->handle => [F::entryLinkPayload($target, 'Second')]]);
    $db = Craft::$app->db;
    $read = fn() => (new Query())->from('{{%hyper_links}}')->where(['ownerId' => [$a->id,$b->id]])->orderBy('id')->all();
    $before = $read();
    // An actual DB write failure must roll back earlier owners in the same migration.
    \Tests\Support\FailingRelationWrites::install('hyper_upgrade_test_fail', $b->id);
    try {
        $migration = new Rebuild();
        $migration->compact = true;
        expect($migration->up())->toBeFalse();
        expect($read())->toBe($before);
    } finally {
        \Tests\Support\FailingRelationWrites::remove('hyper_upgrade_test_fail');
    }
    expect((new Rebuild())->safeUp())->toBeTrue();
    expect(array_map('intval', array_column($read(), 'targetId')))->toBe([$target->id,$target->id]);
});

it('keeps a permanently deleted destination in stored content without indexing it on upgrade or resave', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Deleted target');
    $targetId = $target->id;
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [F::entryLinkPayload($target, 'Retained label')]]);
    expect(Craft::$app->elements->deleteElement($target, true))->toBeTrue();
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $before = $read();
    expect((new Rebuild())->safeUp())->toBeTrue();
    expect($read())->toBe($before);
    expect(LinkRelation::find()->where(['ownerId' => $owner->id])->exists())->toBeFalse();
    $owner = craft\elements\Entry::find()->id($owner->id)->one();
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $link = $owner->getFieldValue($field->handle)->getLinks()[0];
    expect($link->linkValue)->toBe([$targetId]);
    expect($link->getLinkText())->toBe('Retained label');
    expect(LinkRelation::find()->where(['ownerId' => $owner->id])->exists())->toBeFalse();
});
