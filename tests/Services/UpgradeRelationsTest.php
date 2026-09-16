<?php

use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;
use verbb\hyper\records\LinkRelation;

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
