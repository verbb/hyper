<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\migrations\MigrateCraftLinkContent;

function repeatedHyperFixture(): array
{
    $field = F::hyperField();
    $section = F::entrySection($field);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $layout = $type->getFieldLayout();
    $tabs = $layout->getTabs();
    $tabs[0]->setElements([...$tabs[0]->getElements(), new CustomField($field, ['handle' => 'secondHyper'])]);
    $layout->setTabs($tabs);
    $type->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
    $a = F::plainEntry($section, 'A');
    $b = F::plainEntry($section, 'B');
    $owner = F::plainEntry($section, 'Owner', [
        $field->handle => [F::entryLinkPayload($a, 'First')],
        'secondHyper' => [F::entryLinkPayload($b, 'Second')],
    ]);
    return [$field, $owner, $a, $b];
}

function repeatedHyperTargets($field, $owner): array
{
    return array_map('intval', (new Query())->select('targetId')->from('{{%hyper_links}}')
        ->where(['ownerId' => $owner->id, 'ownerSiteId' => $owner->siteId, 'fieldId' => $field->id])
        ->orderBy('sortOrder')->column());
}

it('indexes all repeated placements on saves and retains siblings when one is cleared', function() {
    [$field, $owner, $a, $b] = repeatedHyperFixture();
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
    $owner->setFieldValue($field->handle, []);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id]);
    $owner->setFieldValue('secondHyper', []);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([]);
});

it('preserves both repeated occurrences of the same target', function() {
    [$field, $owner, $a] = repeatedHyperFixture();
    $owner->setFieldValue('secondHyper', [F::entryLinkPayload($a)]);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $a->id]);
});

it('visits all repeated values for dry runs and reconciles every persisted target', function() {
    [$field, $owner, $a, $b] = repeatedHyperFixture();
    expect((new ElementContentStore())->findLayoutUids($field))->toHaveCount(2);
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')
        ->where(['elementId' => $owner->id, 'siteId' => $owner->siteId])->scalar();
    $original = $read();
    $transform = function($collection) use ($a, $b) {
        $link = $collection->getLinks()[0];
        $link->linkValue = [$link->linkValue[0] === $a->id ? $b->id : $a->id];
        return $collection;
    };
    $dry = Hyper::$plugin->content->modify($field, $transform, new ModifyOptions(dryRun: true, elementIds: [$owner->id]));
    expect($dry->wouldModify)->toBe(2);
    expect($read())->toBe($original);
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
    $result = Hyper::$plugin->content->modify($field, $transform, new ModifyOptions(elementIds: [$owner->id]));
    expect($result->modified)->toBe(2);
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id, $a->id]);
    Craft::$app->db->createCommand()->delete('{{%hyper_links}}', ['ownerId' => $owner->id])->execute();
    Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(elementIds: [$owner->id]));
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id, $a->id]);
    Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(elementIds: [$owner->id]));
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id, $a->id]);
});

it('migrates both aliased placements and leaves converted values unchanged on repeat', function() {
    [$field, $owner, $a, $b] = repeatedHyperFixture();
    $uids = (new ElementContentStore())->findLayoutUids($field);
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new \yii\db\JsonExpression([
        $uids[0] => ['type' => 'entry', 'value' => '{entry:' . $b->id . ':url}'],
        $uids[1] => ['type' => 'entry', 'value' => '{entry:' . $a->id . ':url}'],
    ])], $where)->execute();
    $migration = new MigrateCraftLinkContent();
    $migration->fields = [(new Query())->from('{{%fields}}')->where(['id' => $field->id])->one()];
    $migration->processFieldContent();
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $first = $read();
    $owner = Entry::find()->id($owner->id)->siteId($owner->siteId)->one();
    expect($owner->getFieldValue($field->handle)->getLinks()[0]->getElement()?->id)->toBe($b->id);
    expect($owner->getFieldValue('secondHyper')->getLinks()[0]->getElement()?->id)->toBe($a->id);
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id, $a->id]);
    $migration->processFieldContent();
    expect($read())->toBe($first);
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id, $a->id]);
});

it('indexes preserved and newly converted placements in table-backed Typed Link migrations', function() {
    [$field, $owner, $a, $b] = repeatedHyperFixture();
    $db = Craft::$app->db;
    expect($db->tableExists('{{%lenz_linkfield}}'))->toBeFalse();
    $db->createCommand()->createTable('{{%lenz_linkfield}}', [
        'fieldId' => 'integer', 'elementId' => 'integer', 'siteId' => 'integer',
        'type' => 'string', 'linkedId' => 'integer', 'linkedSiteId' => 'integer',
    ])->execute();
    try {
        $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
        $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
        $content = json_decode($read(), true);
        $uid = $owner->getFieldLayout()->getFieldByHandle('secondHyper')->layoutElement->uid;
        $content[$uid] = ['type' => 'entry', 'linkedId' => $b->id];
        $db->createCommand()->update('{{%elements_sites}}', ['content' => new \yii\db\JsonExpression($content)], $where)->execute();
        $db->createCommand()->insert('{{%lenz_linkfield}}', [
            ...$where, 'fieldId' => $field->id, 'type' => 'entry', 'linkedId' => $b->id, 'linkedSiteId' => $owner->siteId,
        ])->execute();
        $migration = new \verbb\hyper\migrations\MigrateTypedLinkContent();
        $migration->fields = [(new Query())->from('{{%fields}}')->where(['id' => $field->id])->one()];
        $migration->processFieldContent();
        expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
        $first = $read();
        $migration->processFieldContent();
        expect($read())->toBe($first);
        expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
    } finally {
        $db->createCommand()->dropTable('{{%lenz_linkfield}}')->execute();
    }
});
