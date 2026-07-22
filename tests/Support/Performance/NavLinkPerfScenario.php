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
            array_map(static fn(Entry $target): array => HyperFixtureFactory::entryLinkPayload($target), $targets),
            'Nav with ' . $linkCount . ' links',
        );

        return [
            'field' => $field,
            'section' => $section,
            'targets' => $targets,
            'owners' => [$owner],
            'expectedLinked' => $linkCount,
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

        foreach ($targets as $target) {
            $owners[] = HyperFixtureFactory::entryWithLinkPayloads(
                $section,
                [HyperFixtureFactory::entryLinkPayload($target, $target->title)],
                'Nav item for ' . $target->title,
            );
        }

        return [
            'field' => $field,
            'section' => $section,
            'targets' => $targets,
            'owners' => $owners,
            'expectedLinked' => $ownerCount,
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

        foreach ($sites as $site) {
            $targets = HyperFixtureFactory::entryTargets($ownersPerSite, $section, $site);

            foreach ($targets as $target) {
                $owners[] = HyperFixtureFactory::entryWithLinkPayloads(
                    $section,
                    [HyperFixtureFactory::entryLinkPayload($target, $target->title)],
                    sprintf('%s nav %s', $site->handle, $target->title),
                    $site,
                );
            }
        }

        return [
            'field' => $field,
            'section' => $section,
            'targets' => [],
            'owners' => $owners,
            'expectedLinked' => $sitesCount * $ownersPerSite,
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
            return self::countResolvedElements($scenario['field'], $scenario['section']);
        });

        $relations->resetRequestState();
        $relations->enableRequestPriming = true;

        $wired = QueryProfiler::profile(function() use ($scenario): int {
            return self::countResolvedElements($scenario['field'], $scenario['section']);
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
            return self::countResolvedUrlsAndText($scenario['field'], $scenario['section']);
        });

        $relations->resetRequestState();

        return $profile;
    }

    public static function countElementCacheQueries(array $profile): int
    {
        $count = 0;

        foreach ($profile['topPatterns'] ?? [] as $query => $hits) {
            if (str_contains($query, 'hyper_element_cache')) {
                $count += $hits;
            }
        }

        return $count;
    }

    private static function countResolvedElements(HyperField $field, Section $section): int
    {
        $linked = 0;

        foreach (Entry::find()->section($section->handle)->all() as $entry) {
            $links = $entry->getFieldValue($field->handle);

            foreach ($links ?? [] as $link) {
                if ($link instanceof ElementLink && $link->getElement()) {
                    $linked++;
                }
            }
        }

        return $linked;
    }

    private static function countResolvedUrlsAndText(HyperField $field, Section $section): int
    {
        $resolved = 0;

        foreach (Entry::find()->section($section->handle)->all() as $entry) {
            $links = $entry->getFieldValue($field->handle);

            foreach ($links ?? [] as $link) {
                if ($link instanceof ElementLink && $link->getUrl() && $link->getLinkText()) {
                    $resolved++;
                }
            }
        }

        return $resolved;
    }
}
