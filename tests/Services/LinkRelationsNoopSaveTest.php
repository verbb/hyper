<?php

declare(strict_types=1);

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

function noopSaveFixture(): array
{
    [$primary, $secondary] = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $targetSection = HyperFixtureFactory::entrySection(null, null, true);
    $targets = [
        HyperFixtureFactory::plainEntry($targetSection, 'First target', [], $primary),
        HyperFixtureFactory::plainEntry($targetSection, 'Second target', [], $primary),
    ];
    $owner = HyperFixtureFactory::entryWithLinkPayloads($section, [
        HyperFixtureFactory::entryLinkPayload($targets[0]),
        HyperFixtureFactory::urlLinkPayload('https://example.test/noop'),
        HyperFixtureFactory::entryLinkPayload($targets[1]),
    ], 'No-op owner', $primary);

    $rows = static fn(): array => (new Query())
        ->select(['id', 'uid', 'ownerSiteId', 'sortOrder', 'targetId', 'targetSiteId'])
        ->from('{{%hyper_links}}')
        ->where(['fieldId' => $field->id, 'ownerId' => $owner->id])
        ->orderBy(['ownerSiteId' => SORT_ASC, 'sortOrder' => SORT_ASC])
        ->all();
    $load = static fn(int $siteId): Entry => Entry::find()->id($owner->id)->siteId($siteId)->status(null)->one();

    return compact('primary', 'secondary', 'field', 'section', 'targets', 'owner', 'rows', 'load');
}

function hyperLinkStatements(array $profile): array
{
    $counts = ['SELECT' => 0, 'INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
    // Match the bare table name, since MySQL and PostgreSQL quote identifiers differently.
    $table = '/(?<!\w)' . preg_quote(Craft::$app->getDb()->getSchema()->getRawTableName('{{%hyper_links}}'), '/') . '(?!\w)/';

    foreach ($profile['patterns'] as $query => $hits) {
        if (preg_match($table, $query) && preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/', $query, $match)) {
            $counts[$match[1]] += $hits;
        }
    }

    return $counts;
}

function expectedNoopRows(array $f): array
{
    $expected = [];

    foreach ([$f['primary'], $f['secondary']] as $site) {
        foreach ([0 => $f['targets'][0], 2 => $f['targets'][1]] as $sortOrder => $target) {
            $expected[] = [$site->id, $sortOrder, $target->id, $site->id];
        }
    }

    return $expected;
}

function rowShape(array $rows): array
{
    return array_map(static fn(array $row): array => [
        (int)$row['ownerSiteId'],
        (int)$row['sortOrder'],
        (int)$row['targetId'],
        (int)$row['targetSiteId'],
    ], $rows);
}

it('leaves relation rows untouched when a save does not change the Hyper field', function() {
    $f = noopSaveFixture();
    $before = ($f['rows'])();

    expect(rowShape($before))->toBe(expectedNoopRows($f));

    // `resave/entries` saves every site with `resaving`, which Craft treats as every field being dirty.
    $resave = QueryProfiler::profile(function() use ($f): int {
        Craft::$app->getElements()->resaveElements(
            Entry::find()->id($f['owner']->id)->siteId('*')->status(null),
            true,
        );

        return 0;
    });

    $owner = ($f['load'])($f['primary']->id);
    $owner->title = 'No-op owner renamed';

    $titleOnly = QueryProfiler::profile(function() use ($owner): bool {
        return Craft::$app->getElements()->saveElement($owner);
    });

    fwrite(STDERR, "\nNo-op save statements: " . json_encode([
        'resave' => ['all' => $resave['queries'], 'hyper_links' => hyperLinkStatements($resave)],
        'titleOnly' => ['all' => $titleOnly['queries'], 'hyper_links' => hyperLinkStatements($titleOnly)],
    ]) . "\n");

    // The resave saves each site and propagates it to the other; the title change saves both sites once.
    foreach ([[$resave, 4], [$titleOnly, 2]] as [$profile, $siteSaves]) {
        $statements = hyperLinkStatements($profile);

        expect($statements['INSERT'] + $statements['UPDATE'] + $statements['DELETE'])->toBe(0);
        // One read of the stored rows per saved site, plus at most one priming read. The lower bound
        // also proves the table match sees this driver's statements, so the write check is meaningful.
        expect($statements['SELECT'])->toBeGreaterThanOrEqual($siteSaves);
        expect($statements['SELECT'])->toBeLessThanOrEqual($siteSaves + 1);
    }

    expect(($f['rows'])())->toBe($before);
});

it('still rewrites relation rows that no longer match the saved content', function() {
    $f = noopSaveFixture();
    $rows = ($f['rows'])();
    $secondaryRows = array_values(array_filter($rows, fn(array $row) => (int)$row['ownerSiteId'] === $f['secondary']->id));

    // A stale index: one row lost, one row with the wrong order and target site, and one stray row.
    Db::delete('{{%hyper_links}}', ['id' => $rows[0]['id']]);
    Db::update('{{%hyper_links}}', ['sortOrder' => 7, 'targetSiteId' => $f['primary']->id], ['id' => $secondaryRows[0]['id']]);
    Db::insert('{{%hyper_links}}', [
        'fieldId' => $f['field']->id,
        'ownerId' => $f['owner']->id,
        'ownerSiteId' => $f['secondary']->id,
        'sortOrder' => 1,
        'linkTypeHandle' => 'entry',
        'targetId' => $f['targets'][0]->id,
        'targetSiteId' => $f['secondary']->id,
        'targetType' => Entry::class,
    ]);

    Craft::$app->getElements()->resaveElements(
        Entry::find()->id($f['owner']->id)->siteId('*')->status(null),
        true,
    );

    expect(rowShape(($f['rows'])()))->toBe(expectedNoopRows($f));

    // A reorder changes only sort orders, which must still be written for every site.
    $owner = ($f['load'])($f['primary']->id);
    $links = $owner->getFieldValue($f['field']->handle);
    $owner->setFieldValue($f['field']->handle, $links->withLinks(array_reverse($links->getLinks())));
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();

    $reordered = [];

    foreach ([$f['primary'], $f['secondary']] as $site) {
        $reordered[] = [$site->id, 0, $f['targets'][1]->id, $site->id];
        $reordered[] = [$site->id, 2, $f['targets'][0]->id, $site->id];
    }

    expect(rowShape(($f['rows'])()))->toBe($reordered);

    // The repair command rebuilds a stale row without a content change.
    $stale = ($f['rows'])()[0];
    Db::update('{{%hyper_links}}', ['targetSiteId' => $f['secondary']->id], ['id' => $stale['id']]);

    expect(Hyper::$plugin->getContent()->reconcileRelations($f['field']))->toBeGreaterThanOrEqual(2);
    expect(rowShape(($f['rows'])()))->toBe($reordered);

    // A permanently deleted target leaves a nulled row that the next save drops.
    expect(Craft::$app->getElements()->deleteElement($f['targets'][1], true))->toBeTrue();
    expect((int)(new Query())->from('{{%hyper_links}}')->where([
        'fieldId' => $f['field']->id, 'ownerId' => $f['owner']->id, 'targetId' => null,
    ])->count())->toBe(2);

    Craft::$app->getElements()->resaveElements(
        Entry::find()->id($f['owner']->id)->siteId('*')->status(null),
        true,
    );

    expect(rowShape(($f['rows'])()))->toBe([
        [$f['primary']->id, 2, $f['targets'][0]->id, $f['primary']->id],
        [$f['secondary']->id, 2, $f['targets'][0]->id, $f['secondary']->id],
    ]);
});

it('rewrites relation rows that a concurrent save changed after this save began', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $a = HyperFixtureFactory::plainEntry($section, 'Kept target');
    $b = HyperFixtureFactory::plainEntry($section, 'Concurrent target');
    $owner = HyperFixtureFactory::plainEntry($section, 'Concurrent owner', [$field->handle => [HyperFixtureFactory::entryLinkPayload($a)]]);
    $where = ['fieldId' => $field->id, 'ownerId' => $owner->id, 'ownerSiteId' => $owner->siteId];
    $targets = static fn(): array => array_map('intval', (new Query())->select('targetId')->from('{{%hyper_links}}')->where($where)->column());

    expect($targets())->toBe([$a->id]);

    $db = Craft::$app->getDb();
    $other = clone $db;
    $other->open();

    // This save's transaction reads first, which fixes its snapshot on MySQL's REPEATABLE READ.
    $transaction = $db->beginTransaction();

    try {
        (new Query())->from('{{%elements}}')->where(['id' => $owner->id])->one($db);

        // A concurrent save commits first and points the index at another target.
        $other->createCommand()->update('{{%hyper_links}}', ['targetId' => $b->id], $where)->execute();

        // This save still links to the first target and writes last.
        $links = new LinkCollection($field, [HyperFixtureFactory::entryLinkPayload($a)], $owner);
        expect(Hyper::$plugin->linkRelations->syncFromLinkCollection($field, $owner, $links))->toBeTrue();

        $transaction->commit();
    } finally {
        if ($transaction->getIsActive()) {
            $transaction->rollBack();
        }

        $other->close();
    }

    expect($targets())->toBe([$a->id]);
});
