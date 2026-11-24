<?php

use craft\db\Query;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\content\Change;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;

function clearedRelationFixture(): array
{
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Cleared relation target');
    $owner = F::plainEntry($section, 'Cleared relation owner', [$field->handle => [F::entryLinkPayload($target)]]);
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $readStored = fn() => Json::decode((new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar());
    $countRows = fn() => (int)(new Query())->from('{{%hyper_links}}')
        ->where(['fieldId' => $field->id, 'ownerId' => $owner->id])
        ->count();
    $relatedIds = fn() => Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'relatedTo' => ['field' => $field->handle, 'targetElement' => $target],
    ])->status(null)->ids();

    return compact('field', 'target', 'owner', 'uid', 'where', 'readStored', 'countRows', 'relatedIds');
}

it('clearing a field through the raw API removes its relation rows', function(mixed $cleared) {
    $f = clearedRelationFixture();
    expect(($f['countRows'])())->toBe(1)->and(($f['relatedIds'])())->toContain($f['owner']->id);

    $result = Hyper::$plugin->getContent()->modifyRaw($f['field'], fn() => Change::replace($cleared), new ModifyOptions(
        elementIds: [$f['owner']->id],
    ));

    expect($result->modified)->toBe(1);
    expect(($f['readStored'])())->toHaveKey($f['uid'])->and(($f['readStored'])()[$f['uid']])->toBe($cleared);
    expect(($f['countRows'])())->toBe(0);
    expect(($f['relatedIds'])())->not->toContain($f['owner']->id);
})->with(['null' => [null], 'empty string' => ['']]);

it('relation reconciliation repairs owners whose stored value is already cleared', function(mixed $cleared) {
    $f = clearedRelationFixture();

    // Simulate an index left behind by an earlier raw clear.
    $content = ($f['readStored'])();
    $content[$f['uid']] = $cleared;
    Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', [
        'content' => new yii\db\Expression(':content', [':content' => json_encode($content, JSON_THROW_ON_ERROR)]),
    ], $f['where'])->execute();
    expect(($f['countRows'])())->toBe(1);

    $options = fn(bool $dryRun) => new ModifyOptions(dryRun: $dryRun, elementIds: [$f['owner']->id]);

    expect(Hyper::$plugin->getContent()->reconcileRelations($f['field'], $options(true)))->toBe(1);
    expect(($f['countRows'])())->toBe(1);

    expect(Hyper::$plugin->getContent()->reconcileRelations($f['field'], $options(false)))->toBe(1);
    expect(($f['countRows'])())->toBe(0);
    expect(($f['relatedIds'])())->not->toContain($f['owner']->id);
    expect(($f['readStored'])()[$f['uid']])->toBe($cleared);
})->with(['null' => [null], 'empty string' => ['']]);
