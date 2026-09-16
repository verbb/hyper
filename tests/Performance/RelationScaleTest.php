<?php

use craft\db\Query;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

it('bounds relation writes across batch boundaries without losing duplicate destinations', function(int $count) {
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Scale destination');
    $owner = F::plainEntry($section, 'Scale owner');
    $links = new LinkCollection($field, array_fill(0, $count, F::entryLinkPayload($target)), $owner);
    // Prime Craft's table metadata and measure replacing an existing relation set.
    expect(Hyper::$plugin->linkRelations->syncFromLinkCollection($field, $owner, $links))->toBeTrue();
    $profile = QueryProfiler::profile(function() use ($field, $owner, $links, $count) {
        expect(Hyper::$plugin->linkRelations->syncFromLinkCollection($field, $owner, $links))->toBeTrue();
        return $count;
    });
    $rows = (new Query())->from('{{%hyper_links}}')->where(['ownerId' => $owner->id, 'fieldId' => $field->id])->orderBy(['sortOrder' => SORT_ASC])->all();
    expect($rows)->toHaveCount($count);
    expect(array_map('intval', array_column($rows, 'sortOrder')))->toBe(range(0, $count - 1));
    expect(array_unique(array_column($rows, 'targetId')))->toEqual([$target->id]);
    expect(array_unique(array_column($rows, 'uid')))->toHaveCount($count);
    expect($profile['queries'])->toBeLessThanOrEqual(2 + (int)ceil($count / 500));
    fwrite(STDERR, "\nRelation scale: " . json_encode(['links' => $count, 'queries' => $profile['queries'], 'durationMs' => $profile['durationMs']]) . "\n");
})->with([30, 500, 1500])->group('perf');
