<?php

use craft\db\Query;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('reads relation rows for large request batches with one IN list per site', function() {
    $sizes = [250, 2500];
    [$primarySite, $otherSite] = F::ensureSites(2);
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Priming scale destination');
    $other = F::plainEntry($section, 'Priming scale other destination');
    $siteId = (int)$target->siteId;
    $otherSiteId = (int)($siteId === (int)$otherSite->id ? $primarySite->id : $otherSite->id);
    $db = Craft::$app->getDb();

    // Owners only need element rows here: this case measures the relation row read that
    // request priming runs for a listing, not authoring or hydration.
    $lastId = (int)(new Query())->from('{{%elements}}')->max('id');
    $elementRows = array_fill(0, max($sizes), [Entry::class, true]);

    foreach (array_chunk($elementRows, 500) as $batch) {
        $db->createCommand()->batchInsert('{{%elements}}', ['type', 'enabled'], $batch)->execute();
    }

    $ownerIds = array_map('intval', (new Query())->select(['id'])->from('{{%elements}}')->where(['>', 'id', $lastId])->andWhere(['type' => Entry::class])->orderBy(['id' => SORT_ASC])->column());
    expect($ownerIds)->toHaveCount(max($sizes));

    // The raw owner rows have no entry rows, so the per-test reset can't find them.
    try {
        $linkRows = [];

        foreach ($ownerIds as $id) {
            $linkRows[] = [$field->id, $id, $siteId, 0, 'entry', $target->id, $siteId, Entry::class];
            $linkRows[] = [$field->id, $id, $siteId, 1, 'entry', $other->id, $siteId, Entry::class];
        }

        // The first 500 owners also hold one row on the other site, so a mixed batch has to keep
        // each owner paired with its own site.
        foreach (array_slice($ownerIds, 0, 500) as $id) {
            $linkRows[] = [$field->id, $id, $otherSiteId, 0, 'entry', $target->id, $siteId, Entry::class];
        }

        foreach (array_chunk($linkRows, 500) as $batch) {
            $db->createCommand()->batchInsert('{{%hyper_links}}', ['fieldId', 'ownerId', 'ownerSiteId', 'sortOrder', 'linkTypeHandle', 'targetId', 'targetSiteId', 'targetType'], $batch)->execute();
        }

        if ($db->getIsMysql()) {
            $db->createCommand('ANALYZE TABLE {{%hyper_links}}')->execute();
        } else {
            $db->createCommand('ANALYZE {{%hyper_links}}')->execute();
        }

        $relations = Hyper::$plugin->getLinkRelations();
        $owners = static fn(array $ids, int $ownerSiteId): array => array_map(static fn(int $id): array => ['ownerId' => $id, 'ownerSiteId' => $ownerSiteId], $ids);

        // The relation row read is the only statement that touches hyper_links.
        $statement = static function(array $batch) use ($relations): string {
            $profile = QueryProfiler::profile(static fn(): array => $relations->getRelationRowsForOwners($batch));
            $statements = array_values(array_filter(array_keys($profile['patterns']), static fn(string $sql): bool => str_contains($sql, 'hyper_links')));

            expect($statements)->toHaveCount(1);

            return $statements[0];
        };

        $timings = [];
        $statements = [];

        foreach ($sizes as $count) {
            $batch = $owners(array_slice($ownerIds, 0, $count), $siteId);
            $relations->getRelationRowsForOwners($batch);
            $runs = [];

            for ($i = 0; $i < 3; $i++) {
                $start = hrtime(true);
                $rows = $relations->getRelationRowsForOwners($batch);
                $runs[] = (hrtime(true) - $start) / 1_000_000;
            }

            sort($runs);
            $timings[$count] = round($runs[1], 2);

            // Every owner's rows come back for its own site, still ordered by sortOrder.
            expect($rows)->toHaveCount($count * 2)
                ->and(array_unique(array_map('intval', array_column($rows, 'ownerId'))))->toHaveCount($count)
                ->and(array_unique(array_map('intval', array_column($rows, 'ownerSiteId'))))->toBe([$siteId])
                ->and(array_map('intval', array_column($rows, 'sortOrder')))->toBe([...array_fill(0, $count, 0), ...array_fill(0, $count, 1)]);

            $statements[$count] = $statement($batch);
        }

        fwrite(STDERR, "\nRequest priming rows: " . json_encode(['ownersMs' => $timings, 'hyperLinksRows' => count($linkRows)]) . "\n");

        // One OR term per owner, which PostgreSQL also makes of a row-value IN, made large
        // listings scale with owners × rows. A single-site batch is one scalar IN on ownerId.
        foreach ($statements as $sql) {
            expect(substr_count($sql, ' OR '))->toBe(0)
                ->and(substr_count($sql, ' IN ('))->toBe(1)
                ->and($sql)->toMatch('/[`"]ownerId[`"] IN \(/');
        }

        // A batch across two sites sends one IN list per site and keeps each owner on its own site.
        $mixed = [
            ...$owners(array_slice($ownerIds, 0, 300), $otherSiteId),
            ...$owners(array_slice($ownerIds, 200, 300), $siteId),
        ];
        $mixedRows = $relations->getRelationRowsForOwners($mixed);
        $keys = array_map(static fn(array $row): string => $row['ownerSiteId'] . ':' . $row['ownerId'] . ':' . $row['sortOrder'], $mixedRows);
        $expected = [];

        foreach (array_slice($ownerIds, 0, 300) as $id) {
            $expected[] = $otherSiteId . ':' . $id . ':0';
        }

        foreach (array_slice($ownerIds, 200, 300) as $id) {
            $expected[] = $siteId . ':' . $id . ':0';
            $expected[] = $siteId . ':' . $id . ':1';
        }

        $mixedSql = $statement($mixed);
        expect($keys)->toEqualCanonicalizing($expected)
            ->and(substr_count($mixedSql, ' OR '))->toBe(1)
            ->and(substr_count($mixedSql, ' IN ('))->toBe(2);

        // A field-scoped owner still matches only that field's rows.
        $scoped = $relations->getRelationRowsForOwners([
            ['ownerId' => $ownerIds[0], 'ownerSiteId' => $siteId, 'fieldId' => $field->id],
            ['ownerId' => $ownerIds[1], 'ownerSiteId' => $siteId, 'fieldId' => $field->id + 1000],
            ['ownerId' => $ownerIds[2], 'ownerSiteId' => $siteId],
        ]);
        expect(array_map(static fn(array $row): string => $row['ownerId'] . ':' . $row['sortOrder'], $scoped))
            ->toEqualCanonicalizing([$ownerIds[0] . ':0', $ownerIds[0] . ':1', $ownerIds[2] . ':0', $ownerIds[2] . ':1']);
    } finally {
        $db->createCommand()->delete('{{%elements}}', ['id' => $ownerIds])->execute();
    }
})->group('perf');
