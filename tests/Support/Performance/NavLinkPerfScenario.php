<?php

declare(strict_types=1);

namespace Tests\Support\Performance;

use craft\elements\Entry;
use craft\models\Section;
use craft\models\Site;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;

final class NavLinkPerfScenario
{
    /**
     * @return array{
     *     field: HyperField,
     *     section: Section,
     *     targets: Entry[],
     *     owners: Entry[],
     *     expectedLinked: int,
     * }
     */
    public static function singleOwnerWithEntryLinks(int $linkCount): array
    {
        $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
        $section = HyperFixtureFactory::entrySection($field, null, true);
        $targets = HyperFixtureFactory::entryTargets($linkCount, $section);
        $owner = HyperFixtureFactory::entryWithLinkPayloads(
            $section,
            array_map(static fn(Entry $target): array => HyperFixtureFactory::entryLinkPayload($target, self::targetLabel($target)), $targets),
            'Nav with ' . $linkCount . ' links',
        );

        return [
            'field' => $field,
            'section' => $section,
            'targets' => $targets,
            'owners' => [$owner],
            'expectedLinked' => $linkCount,
            'expectedRows' => array_map(fn($target) => self::expectedRow($owner, $target), $targets),
        ];
    }

    /**
     * @return array{
     *     field: HyperField,
     *     section: Section,
     *     targets: Entry[],
     *     owners: Entry[],
     *     expectedLinked: int,
     * }
     */
    public static function ownersWithSingleEntryLink(int $ownerCount): array
    {
        $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
        $section = HyperFixtureFactory::entrySection($field, null, true);
        $targets = HyperFixtureFactory::entryTargets($ownerCount, $section);
        $owners = [];
        $expectedRows = [];

        foreach ($targets as $target) {
            $owners[] = HyperFixtureFactory::entryWithLinkPayloads(
                $section,
                [HyperFixtureFactory::entryLinkPayload($target, self::targetLabel($target))],
                'Nav item for ' . $target->title,
            );
        }

        foreach ($owners as $i => $owner) {
            $expectedRows[] = self::expectedRow($owner, $targets[$i]);
        }

        return [
            'field' => $field,
            'section' => $section,
            'targets' => $targets,
            'owners' => $owners,
            'expectedLinked' => $ownerCount,
            'expectedRows' => $expectedRows,
        ];
    }

    /**
     * @return array{
     *     field: HyperField,
     *     section: Section,
     *     targets: Entry[],
     *     owners: Entry[],
     *     expectedLinked: int,
     *     sites: Site[],
     * }
     */
    public static function multisiteOwnersWithSingleEntryLink(int $sitesCount = 3, int $ownersPerSite = 10): array
    {
        $sites = HyperFixtureFactory::ensureSites($sitesCount);
        $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
        $section = HyperFixtureFactory::entrySection($field, null, true);
        $owners = [];
        $expectedRows = [];

        // Reuse target IDs across sites to catch caches accidentally keyed only by ID.
        $targets = HyperFixtureFactory::entryTargets($ownersPerSite, $section, $sites[0]);
        foreach ($sites as $site) {
            foreach ($targets as $original) {
                $target = HyperFixtureFactory::localizedEntryForSite($original, $site);
                $owners[] = HyperFixtureFactory::entryWithLinkPayloads(
                    $section,
                    [HyperFixtureFactory::entryLinkPayload($target, self::targetLabel($target))],
                    sprintf('%s nav %s', $site->handle, $target->title),
                    $site,
                );
                $expectedRows[] = self::expectedRow($owners[array_key_last($owners)], $target);
            }
        }

        return [
            'field' => $field,
            'section' => $section,
            'targets' => [],
            'owners' => $owners,
            'expectedLinked' => $sitesCount * $ownersPerSite,
            'expectedRows' => $expectedRows,
            'sites' => $sites,
        ];
    }

    /**
     * @param array{field: HyperField, section: Section, expectedLinked: int} $scenario
     * @return array{wired: array<string, mixed>, legacy: array<string, mixed>}
     */
    public static function profileLinkedElementHydration(array $scenario): array
    {
        $relations = Hyper::$plugin->getLinkRelations();
        $relations->resetRequestState();
        $relations->enableRequestPriming = false;

        $legacy = QueryProfiler::profile(function() use ($scenario): int {
            return self::countResolvedLinks($scenario);
        });

        $relations->resetRequestState();
        $relations->enableRequestPriming = true;

        $wired = QueryProfiler::profile(function() use ($scenario): int {
            return self::countResolvedLinks($scenario);
        });

        $relations->resetRequestState();

        return [
            'legacy' => $legacy,
            'wired' => $wired,
        ];
    }

    /**
     * Profiles URL/text reads with batch priming and no hyper_element_cache dependency.
     *
     * @param array{field: HyperField, section: Section, expectedLinked: int} $scenario
     * @return array<string, mixed>
     */
    public static function profileUrlTextRead(array $scenario): array
    {
        $relations = Hyper::$plugin->getLinkRelations();
        $relations->resetRequestState();
        $relations->enableRequestPriming = true;

        $profile = QueryProfiler::profile(function() use ($scenario): int {
            return self::countResolvedLinks($scenario);
        });

        $relations->resetRequestState();

        return $profile;
    }

    public static function countElementCacheQueries(array $profile): int
    {
        $count = 0;

        foreach ($profile['patterns'] ?? throw new \RuntimeException('Full query patterns are required for assertions.') as $query => $hits) {
            if (str_contains($query, 'hyper_element_cache')) {
                $count += $hits;
            }
        }

        return $count;
    }

    private static function targetLabel(Entry $target): string
    {
        return 'Target ' . $target->id . ' on site ' . $target->siteId;
    }

    private static function expectedRow(Entry $owner, Entry $target): array
    {
        return [$owner->id, $owner->siteId, $target->id, $target->siteId, $target->getUrl(), self::targetLabel($target)];
    }

    private static function countResolvedLinks(array $scenario): int
    {
        // Propagated copies of an owner must not stand in for its intended site.
        $pairs = ['or'];
        foreach ($scenario['owners'] as $owner) {
            $pairs[] = ['elements.id' => $owner->id, 'elements_sites.siteId' => $owner->siteId];
        }
        $entries = Entry::find()->section($scenario['section']->handle)
            ->siteId(array_values(array_unique(array_column($scenario['expectedRows'], 1))))
            ->andWhere($pairs)->all();
        $actual = [];
        foreach ($entries as $entry) {
            foreach ($entry->getFieldValue($scenario['field']->handle) as $link) {
                $target = $link instanceof ElementLink ? $link->getElement() : null;
                $actual[] = [$entry->id, $entry->siteId, $target?->id, $target?->siteId, $link->getUrl(), $link->getLinkText()];
            }
        }
        $expected = $scenario['expectedRows'];
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new \RuntimeException('Hydration must preserve exact owner/target site pairs, URLs and labels: ' . json_encode(['expected' => $expected, 'actual' => $actual]));
        }
        return count($actual);
    }
}
