<?php

use craft\db\Query;
use Tests\Support\Performance\SaveLinkPerfScenario;
use verbb\hyper\Hyper;

it('rebuilds a large relation set with bounded database work and complete metadata', function() {
    $scenario = SaveLinkPerfScenario::ownerWithEntryLinks(30);
    $profile = SaveLinkPerfScenario::profileRelationSync($scenario);
    fwrite(STDERR, "\nRelation batch audit: " . json_encode($profile) . "\n");
    expect($profile['resultSize'])->toBe(30);
    expect($profile['queries'])->toBeLessThanOrEqual(4);
    $rows = (new Query())->from('{{%hyper_links}}')->where([
        'ownerId' => $scenario['owner']->id,
        'ownerSiteId' => $scenario['owner']->siteId,
        'fieldId' => $scenario['field']->id,
    ])->orderBy(['sortOrder' => SORT_ASC])->all();
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
    $links = $owner->getFieldValue($field->handle);
    // A deleted target is valid historical content; inject a real write failure instead.
    \Tests\Support\FailingRelationWrites::install('hyper_batch_test_fail');
    try {
        expect(fn() => Hyper::$plugin->linkRelations->syncFromLinkCollection($field, $owner, $links))->toThrow(\yii\db\Exception::class);
    } finally {
        \Tests\Support\FailingRelationWrites::remove('hyper_batch_test_fail');
    }
    expect($read())->toBe($before);
});
