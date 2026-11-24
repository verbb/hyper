<?php

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('keeps reverse lookup SQL the same size as incoming links grow', function() {
    $count = 300;
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Reverse lookup destination');
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = F::plainEntry($section, 'Reverse lookup owner ' . $i)->id;
    }

    $insert = static function(array $ownerIds) use ($field, $target): void {
        $rows = array_map(static fn(int $id): array => [$field->id, $id, $target->siteId, 0, 'entry', $target->id, $target->siteId, Entry::class], $ownerIds);

        foreach (array_chunk($rows, 500) as $batch) {
            Craft::$app->getDb()->createCommand()->batchInsert('{{%hyper_links}}', ['fieldId', 'ownerId', 'ownerSiteId', 'sortOrder', 'linkTypeHandle', 'targetId', 'targetSiteId', 'targetType'], $batch)->execute();
        }
    };

    $relations = Hyper::$plugin->getLinkRelations();
    $query = static fn() => $relations->getRelatedElementsQuery(['relatedTo' => ['field' => $field->handle, 'targetElement' => $target]]);

    // The statement sent for a page must not carry one condition per owner.
    $insert(array_slice($ids, 0, 30));
    $smallSql = $query()->limit(10)->getRawSql();
    $insert(array_slice($ids, 30));
    $largeSql = $query()->limit(10)->getRawSql();
    expect(strlen($largeSql))->toBe(strlen($smallSql));

    $profile = QueryProfiler::profile(static fn(): int => count($query()->limit(10)->all()) + $query()->count());
    expect($profile['resultSize'])->toBe(10 + $count);

    fwrite(STDERR, "\nReverse lookup scale: " . json_encode(['owners' => $count, 'sqlBytes' => strlen($largeSql), 'queries' => $profile['queries'], 'durationMs' => $profile['durationMs']]) . "\n");
})->group('perf');
