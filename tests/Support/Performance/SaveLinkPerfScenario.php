<?php

declare(strict_types=1);

namespace Tests\Support\Performance;

use craft\db\Query;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

final class SaveLinkPerfScenario
{
    /**
     * @return array{
     *     field: \verbb\hyper\fields\HyperField,
     *     section: \craft\models\Section,
     *     owner: Entry,
     *     linkCount: int,
     * }
     */
    public static function ownerWithEntryLinks(int $linkCount = 30): array
    {
        $scenario = NavLinkPerfScenario::singleOwnerWithEntryLinks($linkCount);
        $owner = $scenario['owners'][0];

        return [
            'field' => $scenario['field'],
            'section' => $scenario['section'],
            'owner' => $owner,
            'linkCount' => $linkCount,
        ];
    }

    /**
     * @param array{field: \verbb\hyper\fields\HyperField, owner: Entry, linkCount: int} $scenario
     * @return array<string, mixed>
     */
    public static function profileRelationSync(array $scenario): array
    {
        $owner = self::reloadOwner($scenario['owner']);

        if (!$owner) {
            throw new \RuntimeException('Owner entry missing for relation sync perf scenario.');
        }

        return QueryProfiler::profile(function() use ($scenario, $owner): int {
            $links = $owner->getFieldValue($scenario['field']->handle);

            if (!$links instanceof LinkCollection) {
                return 0;
            }

            Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($scenario['field'], $owner, $links);

            return (int)(new Query())
                ->from('{{%hyper_links}}')
                ->where([
                    'ownerId' => $owner->id,
                    'ownerSiteId' => $owner->siteId,
                    'fieldId' => $scenario['field']->id,
                ])
                ->count();
        });
    }

    /**
     * Simulates the legacy save hook that called getElement() per link via upsertCache().
     *
     * @param array{field: \verbb\hyper\fields\HyperField, owner: Entry, linkCount: int} $scenario
     * @return array<string, mixed>
     */
    public static function profileLegacyUpsertCache(array $scenario): array
    {
        $owner = self::reloadOwner($scenario['owner']);

        if (!$owner) {
            throw new \RuntimeException('Owner entry missing for upsertCache perf scenario.');
        }

        return QueryProfiler::profile(function() use ($scenario, $owner): int {
            LegacyElementCacheSeeder::seedFromOwner($scenario['field'], $owner);

            return (int)(new Query())
                ->from('{{%hyper_element_cache}}')
                ->where([
                    'sourceId' => $owner->id,
                    'fieldId' => $scenario['field']->id,
                ])
                ->count();
        });
    }

    /**
     * @param array{field: \verbb\hyper\fields\HyperField, owner: Entry} $scenario
     */
    public static function countPerLinkElementQueries(array $profile): int
    {
        $pattern = 'LIMIT ?';
        $count = 0;

        foreach ($profile['topPatterns'] ?? [] as $query => $hits) {
            if (str_contains($query, 'elements`.`id`=?)') && str_contains($query, $pattern)) {
                $count += $hits;
            }
        }

        return $count;
    }

    private static function reloadOwner(Entry $owner): ?Entry
    {
        return Entry::find()
            ->id($owner->id)
            ->siteId($owner->siteId)
            ->status(null)
            ->one();
    }
}
