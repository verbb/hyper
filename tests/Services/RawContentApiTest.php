<?php

use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\content\Change;
use verbb\hyper\Hyper;

function hyperRawFixture(): array
{
    $target = new PlainText(['name' => 'Source', 'handle' => F::handle('rawSource')]);
    Craft::$app->fields->saveField($target);
    $field = F::hyperField();
    $link = $field->getLinkTypeByHandle('url');
    $layout = new FieldLayout(['type' => $link::class]);
    $a = new CustomField($target, ['uid' => StringHelper::UUID()]);
    $b = new CustomField($target, ['uid' => StringHelper::UUID(), 'handle' => F::handle('second')]);
    $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [$a, $b]])]);
    $link->setFieldLayout($layout);
    $field->setLinkTypes([$link]);
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $owner = F::plainEntry(F::entrySectionWithField($field), 'Raw owner');
    $placement = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $value = [['uid' => StringHelper::UUID(), 'linkTypeHandle' => 'url', 'linkValue' => 'https://example.test', 'fields' => [$a->uid => 'old', $b->uid => null, 'orphan' => ['fields' => [$a->uid => 'keep']]]]];
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$placement => $value])], $where)->execute();
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $map = Hyper::$plugin->getContent()->captureFieldLocations($target->uid);
    return compact('field', 'owner', 'target', 'a', 'b', 'value', 'map', 'read');
}

it('Hyper raw API uses captured placements without normalising source fields', function() {
    $f = hyperRawFixture();
    $api = Hyper::$plugin->getContent();
    $map = Json::decode(Json::encode($f['map']));
    $contexts = [];
    $out = $api->transformValue($f['value'], $f['field']->uid, $map, function($raw, $context) use (&$contexts) {
        $contexts[] = $context;
        return $raw === 'old' ? Change::replace([]) : Change::unchanged();
    });
    expect($out['matched'])->toBe(2)->and($out['changed'])->toBe(1);
    expect($out['value'][0]['fields'][$f['a']->uid])->toBe([])->and($out['value'][0]['fields'][$f['b']->uid])->toBeNull();
    expect($out['value'][0]['fields']['orphan'])->toBe($f['value'][0]['fields']['orphan']);
    expect($contexts[0]['hasDurableOwner'])->toBeFalse();
});

it('Hyper raw API dry run and transaction rollback preserve stored values', function() {
    $f = hyperRawFixture();
    $api = Hyper::$plugin->getContent();
    $before = ($f['read'])();
    $options = ['elementIds' => [$f['owner']->id], 'batchSize' => 1];
    $result = $api->modifyFieldValues($f['map'], fn() => Change::remove(), $options + ['dryRun' => true]);
    expect($result['wouldModify'])->toBe(2)->and(($f['read'])())->toBe($before);
    expect(fn() => $api->modifyFieldValues($f['map'], fn() => Change::remove(), $options))->toThrow(RuntimeException::class);
    $tx = Craft::$app->db->beginTransaction();
    try {
        expect($api->modifyFieldValues($f['map'], fn() => Change::replace('done'), $options)['modified'])->toBe(1);
        expect($api->modifyFieldValues($f['map'], fn() => Change::replace('done'), $options)['modified'])->toBe(0);
    } finally { $tx->rollBack(); }
    expect(($f['read'])())->toBe($before);
});


it('Hyper raw API retains legacy link identities and propagates failures', function() {
    $f = hyperRawFixture();
    $value = $f['value'];
    unset($value[0]['uid'], $value[0]['linkTypeHandle']);
    $value[0]['type'] = verbb\hyper\links\Url::class;
    $contexts = [];
    $result = Hyper::$plugin->getContent()->transformValue($value, $f['field']->uid, $f['map'], function($raw, $context) use (&$contexts) {
        $contexts[] = $context;
        return Change::replace(false);
    });
    expect($result['value'][0])->not->toHaveKey('uid');
    expect($result['value'][0]['type'])->toBe(verbb\hyper\links\Url::class);
    expect($contexts[0]['path'][0]['linkUid'])->toBeNull();
    expect(fn() => Hyper::$plugin->getContent()->transformValue($value, $f['field']->uid, $f['map'], fn() => throw new RuntimeException('consumer')))->toThrow(RuntimeException::class, 'consumer');
});

it('Hyper raw migration converts before destination interpretation', function() {
    $f = hyperRawFixture();
    $field = new class extends verbb\hyper\fields\HyperField {
        public function getLinkTypes(): array { throw new RuntimeException('Destination interpreter must not run'); }
    };
    $field->id = $f['field']->id;
    $field->uid = $f['field']->uid;
    $result = Hyper::$plugin->getContent()->modifyRaw($field, fn($raw) => Change::replace([]), new verbb\hyper\content\ModifyOptions(
        syncRelations: false, includeNested: false, elementIds: [$f['owner']->id],
    ));
    expect($result->modified)->toBe(1);
});


it('Hyper raw API invalidates caches only after the outer transaction commits', function() {
    $f = hyperRawFixture();
    $events = 0;
    $listener = function() use (&$events) { $events++; };
    Craft::$app->elements->on(craft\services\Elements::EVENT_INVALIDATE_CACHES, $listener);
    $options = new verbb\hyper\content\ModifyOptions(syncRelations: false, includeNested: false, elementIds: [$f['owner']->id]);
    try {
        $options->dryRun = true;
        Hyper::$plugin->getContent()->modifyRaw($f['field'], fn() => Change::replace([]), $options);
        expect($events)->toBe(0);
        $options->dryRun = false;
        $outer = Craft::$app->db->beginTransaction();
        try {
            Hyper::$plugin->getContent()->modifyRaw($f['field'], fn() => Change::replace([]), $options);
            expect($events)->toBe(0);
        } finally { $outer->rollBack(); }
        expect($events)->toBe(0);
        Hyper::$plugin->getContent()->modifyRaw($f['field'], fn() => Change::replace([]), $options);
        expect($events)->toBe(1);
    } finally { Craft::$app->elements->off(craft\services\Elements::EVENT_INVALIDATE_CACHES, $listener); }
});

it('raw embedded writes detect concurrent changes to JSON object shapes', function() {
    $f = hyperRawFixture();
    $db = Craft::$app->db;
    $where = ['elementId' => $f['owner']->id, 'siteId' => $f['owner']->siteId];
    $source = json_decode(($f['read'])());
    $source->untouched = new stdClass();
    $db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\Expression(':content', [':content' => json_encode($source)])], $where)->execute();
    $source->untouched = [];
    $writer = new \craft\db\Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password, 'tablePrefix' => $db->tablePrefix]);
    $written = false;
    $callback = function() use ($writer, $where, $source, &$written) {
        if (!$written) {
            $writer->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\Expression(':content', [':content' => json_encode($source)])], $where)->execute();
            $written = true;
        }
        return Change::replace('migration value');
    };
    try {
        expect(fn() => $db->transaction(fn() => Hyper::$plugin->content->modifyFieldValues($f['map'], $callback, ['elementIds' => [$f['owner']->id]])))
            ->toThrow(RuntimeException::class, 'Content changed concurrently');
        expect(json_decode(($f['read'])())->untouched)->toBe([]);
        $after = Json::decode(($f['read'])());
        $root = array_key_first($f['map']['roots']);
        // Locate the fixture's root, since other tests may have registered more containers.
        foreach ($f['map']['roots'] as $key => $definition) {
            if ($definition['fieldUid'] === $f['field']->uid && isset($after[$key])) $root = $key;
        }
        expect($after[$root][0]['fields'][$f['a']->uid])->toBe('old');
    } finally {
        $writer->close();
    }
});

it('invalidates committed raw writes independently across database connections', function(bool $commitFirst) {
    $first = hyperRawFixture();
    $second = hyperRawFixture();
    $db = Craft::$app->db;
    $writer = new \craft\db\Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password, 'tablePrefix' => $db->tablePrefix]);
    $beforeFirst = ($first['read'])();
    $beforeSecond = ($second['read'])();
    $events = 0;
    $listener = function() use (&$events) { $events++; };
    Craft::$app->elements->on(craft\services\Elements::EVENT_INVALIDATE_CACHES, $listener);
    $firstTransaction = $db->beginTransaction();
    $secondTransaction = $writer->beginTransaction();
    try {
        // Consumers retain a coordinator when registering their own container adapters.
        $api = Hyper::$plugin->content->rawContent();
        expect($api->modifyFieldValues($first['map'], fn() => Change::replace('first update'), ['db' => $db, 'elementIds' => [$first['owner']->id]])['modified'])->toBe(1);
        expect($api->modifyFieldValues($second['map'], fn() => Change::replace('second update'), ['db' => $writer, 'elementIds' => [$second['owner']->id]])['modified'])->toBe(1);
        expect($events)->toBe(0);
        if ($commitFirst) {
            $firstTransaction->commit();
        } else {
            $firstTransaction->rollBack();
        }
        $secondTransaction->commit();
        expect(($second['read'])())->not->toBe($beforeSecond);
        if (!$commitFirst) {
            expect(($first['read'])())->toBe($beforeFirst);
        }
        expect($events)->toBe($commitFirst ? 2 : 1);
    } finally {
        if ($firstTransaction->getIsActive()) $firstTransaction->rollBack();
        if ($secondTransaction->getIsActive()) $secondTransaction->rollBack();
        Craft::$app->elements->off(craft\services\Elements::EVENT_INVALIDATE_CACHES, $listener);
        $writer->close();
    }
})->with(['first commits' => [true], 'first rolls back' => [false]]);
