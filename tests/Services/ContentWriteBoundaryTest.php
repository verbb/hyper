<?php

use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\content\Change;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

function contentWriteFixture(): array
{
    $field = F::hyperField();
    $owner = F::entryWithLinks(F::entrySection($field), [F::urlLinkPayload('https://example.test/before')]);
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $write = fn($value) => Craft::$app->db->createCommand()->update('{{%elements_sites}}', [
        'content' => new yii\db\Expression(':content', [':content' => json_encode($value, JSON_THROW_ON_ERROR)]),
    ], $where)->execute();
    return compact('field', 'owner', 'uid', 'where', 'read', 'write');
}

it('an empty content owner selection never visits or changes any owner', function(bool $raw) {
    $f = contentWriteFixture();
    $before = ($f['read'])();
    $calls = 0;
    $callback = function() use (&$calls, $raw, $f) {
        $calls++;
        return $raw ? Change::replace([]) : new LinkCollection($f['field'], []);
    };
    $method = $raw ? 'modifyRaw' : 'modify';
    $result = Hyper::$plugin->getContent()->$method($f['field'], $callback, new ModifyOptions(elementIds: []));
    expect($calls)->toBe(0)->and($result->matched)->toBe(0)->and($result->modified)->toBe(0);
    expect(($f['read'])())->toBe($before);
})->with([false, true]);

it('raw whole-field writes preserve unrelated JSON object shapes', function() {
    $f = contentWriteFixture();
    $source = json_decode(($f['read'])());
    $source->untouched = (object)['empty' => new stdClass(), 'numeric' => (object)['0' => 'zero', '1' => 'one']];
    ($f['write'])($source);
    Hyper::$plugin->getContent()->modifyRaw($f['field'], fn() => Change::replace([]), new ModifyOptions(
        syncRelations: false, includeNested: false, elementIds: [$f['owner']->id],
    ));
    $after = json_decode(($f['read'])());
    expect($after->{$f['uid']})->toBe([]);
    expect($after->untouched->empty)->toBeInstanceOf(stdClass::class);
    expect($after->untouched->numeric)->toBeInstanceOf(stdClass::class);
    expect((array)$after->untouched->numeric)->toBe([0 => 'zero', 1 => 'one']);
});

it('content prefilter searches beyond the first sixteen kilobytes', function() {
    $f = contentWriteFixture();
    $source = Json::decode(($f['read'])());
    $source[$f['uid']][0]['linkValue'] = 'https://example.test/' . str_repeat('a', 20000) . '/unique-end-marker';
    ($f['write'])($source);
    $result = Hyper::$plugin->getContent()->modifyRaw($f['field'], fn() => Change::unchanged(), new ModifyOptions(
        dryRun: true, includeNested: false, elementIds: [$f['owner']->id], contentContains: 'unique-end-marker',
    ));
    expect($result->matched)->toBe(1);
});

it('embedded content obeys the same content prefilter as durable values', function() {
    $target = F::hyperField();
    $host = F::hyperField();
    $link = $host->getLinkTypeByHandle('url');
    $layout = $link->getFieldLayout();
    $tab = $layout->getTabs()[0];
    $placement = new CustomField($target, ['uid' => StringHelper::UUID()]);
    $tab->setElements([...$tab->getElements(), $placement]);
    $link->setFieldLayout($layout);
    $host->setLinkTypes([$link]);
    expect(Craft::$app->fields->saveField($host))->toBeTrue();
    $owner = F::plainEntry(F::entrySectionWithField($host));
    $hostUid = $owner->getFieldLayout()->getFieldByHandle($host->handle)->layoutElement->uid;
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([
        $hostUid => [['uid' => StringHelper::UUID(), 'linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/host',
            'fields' => [$placement->uid => [F::urlLinkPayload('https://example.test/nested-marker')]]]],
    ])], ['elementId' => $owner->id, 'siteId' => $owner->siteId])->execute();
    $api = Hyper::$plugin->getContent();
    $missing = $api->modifyRaw($target, fn() => Change::replace([]), new ModifyOptions(
        elementIds: [$owner->id], contentContains: 'no-such-marker',
    ));
    expect($missing->matched)->toBe(0)->and($missing->modified)->toBe(0);
    $matching = $api->modifyRaw($target, fn() => Change::replace([]), new ModifyOptions(
        elementIds: [$owner->id], contentContains: 'nested-marker',
    ));
    expect($matching->matched)->toBe(1)->and($matching->modified)->toBe(1);
});

it('expanding Matrix owners retains explicit migration replacement options', function() {
    ['matrix' => $matrix, 'blockEntryType' => $type, 'hyperField' => $field] = F::matrixFieldWithHyper();
    $owner = F::entryWithMatrixHyperLink(F::entrySectionWithField($matrix), $matrix, $field, $type, []);
    $block = $owner->getFieldValue($matrix->handle)->one();
    $uid = $block->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $block->id, 'siteId' => $block->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$uid => ['url' => '']])], $where)->execute();
    $options = new ModifyOptions(elementIds: [$owner->id], persistTransformedValues: true);
    $flags = [];
    (new \verbb\hyper\content\ElementContentStore())->eachFieldValue($field, function($ref, $expandedOptions) use (&$flags) {
        $flags[] = $expandedOptions->persistTransformedValues;
        return false;
    }, $options);
    expect($flags)->toBe([true]);
    $result = Hyper::$plugin->getContent()->modify($field, fn() => new LinkCollection($field, []), new ModifyOptions(
        elementIds: [$owner->id], persistTransformedValues: true,
    ));
    expect($result->modified)->toBe(1);
    $raw = (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    expect(Json::decode($raw)[$uid])->toBe([]);
});

it('whole-field writes reject an owner changed after the migration snapshot', function() {
    $f = contentWriteFixture();
    $before = ($f['read'])();
    $source = Json::decode($before);
    $source['sibling'] = 'changed after scan';
    $db = Craft::$app->db;
    $writer = new \craft\db\Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password, 'tablePrefix' => $db->tablePrefix]);
    $callback = function() use ($f, $writer, $source) {
        // Commit through an independent connection while the migration still holds
        // its older read snapshot. Its rollback must not undo the other writer.
        $writer->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression($source)], $f['where'])->execute();
        return Change::replace([]);
    };
    try {
        expect(fn() => Hyper::$plugin->getContent()->modifyRaw($f['field'], $callback, new ModifyOptions(
            syncRelations: false, includeNested: false, elementIds: [$f['owner']->id],
        )))->toThrow(RuntimeException::class, 'Content changed concurrently');
        $after = Json::decode(($f['read'])());
        expect($after['sibling'])->toBe('changed after scan');
        expect($after[$f['uid']])->toBe($source[$f['uid']]);
    } finally {
        $writer->close();
    }
});
