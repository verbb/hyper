<?php

use craft\db\Query;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;

it('reads a large incoming-link set and releases explicitly reset batch state', function() {
    $count = 1000;
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Incoming links destination');
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $ids[] = F::plainEntry($section, 'Large read ' . $i)->id;
    }

    // Populate the derived index directly: this case measures reads, not authoring.
    $rows = [];
    foreach ($ids as $i => $id) {
        $rows[] = [$field->id, $id, $target->siteId, 0, 'entry', $target->id, $target->siteId, Entry::class];
        $rows[] = [$field->id, $id, $target->siteId, 1, 'entry', $ids[($i + 1) % $count], $target->siteId, Entry::class];
    }
    foreach (array_chunk($rows, 500) as $batch) {
        Craft::$app->getDb()->createCommand()->batchInsert('{{%hyper_links}}', ['fieldId', 'ownerId', 'ownerSiteId', 'sortOrder', 'linkTypeHandle', 'targetId', 'targetSiteId', 'targetType'], $batch)->execute();
    }

    $relations = Hyper::$plugin->getLinkRelations();
    $relations->resetRequestState();
    $start = microtime(true);
    $memory = memory_get_usage();
    $query = $relations->getRelatedElementsQuery(['relatedTo' => ['field' => $field->handle, 'targetElement' => $target]]);
    $queryMemory = memory_get_usage() - $memory;
    $actual = $query->orderBy(['elements.id' => SORT_ASC])->ids();
    expect(array_map('intval', $actual))->toBe($ids);
    $incomingMs = (microtime(true) - $start) * 1000;

    $sizes = [];
    $retained = new ReflectionProperty($relations, '_hydratedElements');
    foreach ([false, true] as $reset) {
        $relations->resetRequestState();
        gc_collect_cycles();
        $baseline = memory_get_usage();
        $batchMemory = [];
        $start = microtime(true);
        foreach (array_chunk($ids, 100) as $batch) {
            foreach ($batch as $id) {
                $relations->registerOwner($id, $target->siteId);
            }
            $relations->primePendingOwners();
            expect($relations->getPrimedElement($target->id, $target->siteId)?->id)->toBe($target->id);
            if ($reset) {
                $relations->resetRequestState();
            }
            gc_collect_cycles();
            $batchMemory[] = memory_get_usage() - $baseline;
        }
        $sizes[$reset ? 'resetEachBatch' : 'oneLongRequest'] = ['memoryBytesByBatch' => $batchMemory, 'retainedTargets' => count($retained->getValue($relations)), 'durationMs' => (microtime(true) - $start) * 1000];
        expect(count($retained->getValue($relations)))->toBe($reset ? 0 : $count + 1);
    }
    fwrite(STDERR, "\nLarge reads: " . json_encode(['owners' => $count, 'incomingMs' => $incomingMs, 'queryMemoryBytes' => $queryMemory, 'batches' => $sizes]) . "\n");
})->group('perf-large');
