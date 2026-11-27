<?php

declare(strict_types=1);

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\content\Change;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\LegacyContentRelationRebuilder;
use verbb\hyper\records\LinkRelation;

/**
 * Copy an entry's stored content rows onto new bare elements, padded to the given size.
 *
 * @return int[] The new rows' IDs.
 */
function contentScanPagingCopies(Entry $entry, int $copies, int $padding = 0): array
{
    $db = Craft::$app->getDb();
    $template = (new Query())->select(['siteId', 'content'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id])->pairs();
    $template = array_map(fn($content) => [...Json::decode($content), 'scan-padding' => str_repeat('x', $padding)], $template);
    $now = Db::prepareDateForDb(new DateTime());
    $rowIds = [];

    foreach (array_chunk(range(1, $copies), 250) as $chunk) {
        $db->createCommand()->batchInsert(
            Table::ELEMENTS,
            ['type', 'enabled', 'archived', 'dateCreated', 'dateUpdated', 'uid'],
            array_map(fn() => [Entry::class, true, false, $now, $now, StringHelper::UUID()], $chunk)
        )->execute();
        $firstId = (int)$db->getLastInsertID();
        $rows = [];

        foreach (range($firstId, $firstId + count($chunk) - 1) as $elementId) {
            foreach ($template as $siteId => $content) {
                $rows[] = [$elementId, $siteId, $content, true, $now, $now, StringHelper::UUID()];
            }
        }

        $db->createCommand()->batchInsert(Table::ELEMENTS_SITES, ['elementId', 'siteId', 'content', 'enabled', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();
        $rowIds = [...$rowIds, ...(new Query())->select('id')->from(Table::ELEMENTS_SITES)->where(['elementId' => range($firstId, $firstId + count($chunk) - 1)])->column()];
    }

    return array_map('intval', $rowIds);
}

function contentScanPagingPeakMb(callable $fn): float
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $fn();

    return (memory_get_peak_usage() - $base) / 1048576;
}

it('visits and saves every matching row when a raw content scan spans several pages', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $entry = F::entryWithLinks($section, [F::urlLinkPayload('https://example.test/paged', 'Paged')]);
    $rowIds = contentScanPagingCopies($entry, 250);
    $layoutUid = (new ElementContentStore())->findLayoutUids($field)[0];
    // The entry's revision stores the same value, so count every stored row that holds the field.
    $expected = (int)(new Query())->from(Table::ELEMENTS_SITES)->where(Craft::$app->getDb()->getQueryBuilder()->jsonExtract('content', [$layoutUid]) . ' IS NOT NULL')->count();

    expect(count($rowIds))->toBeGreaterThan(200)
        ->and($expected)->toBeGreaterThan(count($rowIds));

    $result = Hyper::$plugin->getContent()->modifyRaw($field, fn() => Change::replace(null), new ModifyOptions(syncRelations: false));

    expect($result->matched)->toBe($expected)
        ->and($result->modified)->toBe($expected);

    $cleared = 0;

    foreach ((new Query())->select('content')->from(Table::ELEMENTS_SITES)->where(['id' => $rowIds])->column() as $content) {
        $content = Json::decode($content);

        if (array_key_exists($layoutUid, $content) && $content[$layoutUid] === null) {
            $cleared++;
        }
    }

    expect($cleared)->toBe(count($rowIds));
});

it('keeps memory flat when raw content scans cover many large rows', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $entry = F::entryWithLinks($section, [F::urlLinkPayload('https://example.test/large', 'Large')]);
    $rowIds = contentScanPagingCopies($entry, 2000, 8000);

    expect(count($rowIds))->toBeGreaterThanOrEqual(2000);

    // Buffering every row of about 8 KB takes over 30 MB here, and grows with the site.
    $modify = contentScanPagingPeakMb(fn() => Hyper::$plugin->getContent()->modifyRaw($field, fn() => Change::unchanged(), new ModifyOptions(syncRelations: false)));
    $reconcile = contentScanPagingPeakMb(fn() => Hyper::$plugin->getContent()->reconcileRelations($field));

    expect($modify)->toBeLessThan(12.0)
        ->and($reconcile)->toBeLessThan(12.0);
});

it('rebuilds legacy content table relations beyond the first page of rows', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Paged legacy target');
    $owner = F::plainEntry($section, 'Paged legacy owner');
    $table = '{{%hyper_test_paged_legacy_content}}';
    $column = sprintf('field_%s%s', $field->handle, $field->columnSuffix ? '_' . $field->columnSuffix : '');
    $migration = new class () extends Migration {
        public function safeUp(): bool
        {
            return true;
        }

        public function safeDown(): bool
        {
            return true;
        }
    };

    $migration->createTable($table, [
        'id' => $migration->primaryKey(),
        'elementId' => $migration->integer()->notNull(),
        'siteId' => $migration->integer()->notNull(),
        $column => $migration->text(),
    ]);
    Craft::$app->db->getSchema()->refresh();

    try {
        $value = Json::encode([F::entryLinkPayload($target, 'Paged legacy link')]);
        $missingOwnerId = (int)(new Query())->from(Table::ELEMENTS)->max('id') + 1000;

        // Rows for an owner that no longer exists fill the first page, so the real owner is on the second.
        Craft::$app->db->createCommand()->batchInsert(
            $table,
            ['elementId', 'siteId', $column],
            array_map(fn() => [$missingOwnerId, $owner->siteId, $value], range(1, 150))
        )->execute();
        Craft::$app->db->createCommand()->insert($table, ['elementId' => $owner->id, 'siteId' => $owner->siteId, $column => $value])->execute();

        (new LegacyContentRelationRebuilder())->rebuild($field);

        $row = LinkRelation::find()->where(['fieldId' => $field->id, 'ownerId' => $owner->id, 'ownerSiteId' => $owner->siteId])->one();

        expect($row)->not->toBeNull()
            ->and((int)$row->targetId)->toBe($target->id);
    } finally {
        $migration->dropTableIfExists($table);
        Craft::$app->db->getSchema()->refresh();
    }
});
