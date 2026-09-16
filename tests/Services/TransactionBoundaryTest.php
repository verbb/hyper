<?php

use craft\db\Query;
use Tests\Support\FailingRelationWrites;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\records\LinkRelation;

it('rolls back an owner save when its relation index cannot be written', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $first = F::plainEntry($section, 'First target');
    $second = F::plainEntry($section, 'Second target');
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [['handle' => 'entry', 'linkValue' => [$first->id]]]]);
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $before = $read();
    $owner->setFieldValue($field->handle, [['handle' => 'entry', 'linkValue' => [$second->id]]]);
    FailingRelationWrites::install('hyper_owner_save_failure', $owner->id);
    try {
        expect(fn() => Craft::$app->elements->saveElement($owner))->toThrow(\yii\db\Exception::class, 'Injected relation write failure');
    } finally {
        FailingRelationWrites::remove('hyper_owner_save_failure');
    }
    expect($read())->toBe($before);
    expect((int)LinkRelation::find()->where(['ownerId' => $owner->id])->one()->targetId)->toBe($first->id);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect((int)LinkRelation::find()->where(['ownerId' => $owner->id])->one()->targetId)->toBe($second->id);
});

it('rolls back a table-backed migration on relation failure and supports retry', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $first = F::plainEntry($section, 'Existing target');
    $second = F::plainEntry($section, 'Migrated target');
    $owner = F::plainEntry($section, 'Migration owner', [$field->handle => [['handle' => 'entry', 'linkValue' => [$first->id]]]]);
    $db = Craft::$app->db;
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $db->createCommand()->update('{{%elements_sites}}', ['content' => new \yii\db\JsonExpression([$uid => ['type' => 'entry', 'linkedId' => $second->id]])], $where)->execute();
    $before = $read();
    expect($db->tableExists('{{%lenz_linkfield}}'))->toBeFalse();
    $db->createCommand()->createTable('{{%lenz_linkfield}}', [
        'fieldId' => 'integer', 'elementId' => 'integer', 'siteId' => 'integer',
        'type' => 'string', 'linkedId' => 'integer', 'linkedSiteId' => 'integer',
    ])->execute();
    try {
        $db->createCommand()->insert('{{%lenz_linkfield}}', [...$where, 'fieldId' => $field->id, 'type' => 'entry', 'linkedId' => $second->id, 'linkedSiteId' => $owner->siteId])->execute();
        FailingRelationWrites::install('hyper_legacy_migration_failure', $owner->id);
        try {
            expect((new MigrateTypedLinkContent())->up())->toBeFalse();
        } finally {
            FailingRelationWrites::remove('hyper_legacy_migration_failure');
        }
        expect($read())->toBe($before);
        expect((int)LinkRelation::find()->where(['ownerId' => $owner->id])->one()->targetId)->toBe($first->id);
        expect((new MigrateTypedLinkContent())->up())->not->toBeFalse();
        expect((int)LinkRelation::find()->where(['ownerId' => $owner->id])->one()->targetId)->toBe($second->id);
        expect(json_decode($read(), true)[$uid][0]['linkValue'])->toBe($second->id);
    } finally {
        $db->createCommand()->dropTable('{{%lenz_linkfield}}')->execute();
    }
});
