<?php

use craft\db\Query;
use craft\helpers\Db;
use Tests\Support\Performance\SaveLinkPerfScenario;
use verbb\hyper\Hyper;

it('rebuilds a large relation set with bounded database work and complete metadata', function() {
    $scenario = SaveLinkPerfScenario::ownerWithEntryLinks(30);
    $where = [
        'ownerId' => $scenario['owner']->id,
        'ownerSiteId' => $scenario['owner']->siteId,
        'fieldId' => $scenario['field']->id,
    ];
    $staleIds = (new Query())->select('id')->from('{{%hyper_links}}')->where($where)->column();
    // Make every stored row stale, since an unchanged index is not rewritten.
    Db::update('{{%hyper_links}}', ['linkTypeHandle' => 'stale'], $where);
    $profile = SaveLinkPerfScenario::profileRelationSync($scenario);
    fwrite(STDERR, "\nRelation batch audit: " . json_encode($profile) . "\n");
    expect($profile['resultSize'])->toBe(30);
    expect($profile['queries'])->toBeLessThanOrEqual(5);
    $rows = (new Query())->from('{{%hyper_links}}')->where($where)->orderBy(['sortOrder' => SORT_ASC])->all();
    expect($staleIds)->toHaveCount(30);
    expect(array_intersect(array_column($rows, 'id'), $staleIds))->toBe([]);
    expect(array_column($rows, 'sortOrder'))->toEqual(range(0, 29));
    expect(array_unique(array_column($rows, 'uid')))->toHaveCount(30);
    foreach ($rows as $row) {
        expect($row['dateCreated'])->not->toBeEmpty();
        expect($row['dateUpdated'])->not->toBeEmpty();
    }
});

it('rolls back a failed relation replacement without losing the old index', function() {
    $scenario = SaveLinkPerfScenario::ownerWithEntryLinks(2);
    $owner = $scenario['owner'];
    $field = $scenario['field'];
    $read = fn() => (new Query())->from('{{%hyper_links}}')->where(['ownerId' => $owner->id, 'fieldId' => $field->id])->orderBy(['id' => SORT_ASC])->all();
    $before = $read();
    // Reordering changes the index, since an unchanged index is not rewritten.
    $links = $owner->getFieldValue($field->handle);
    $links = $links->withLinks(array_reverse($links->getLinks()));
    // A deleted target is valid historical content; inject a real write failure instead.
    \Tests\Support\FailingRelationWrites::install('hyper_batch_test_fail');
    try {
        expect(fn() => Hyper::$plugin->linkRelations->syncFromLinkCollection($field, $owner, $links))->toThrow(\yii\db\Exception::class);
    } finally {
        \Tests\Support\FailingRelationWrites::remove('hyper_batch_test_fail');
    }
    expect($read())->toBe($before);
});
