<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\QueryProfiler;

it('captures baseline query counts for reading Hyper link fields', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    HyperFixtureFactory::entries(10, $section);

    $profile = QueryProfiler::profile(function() use ($section, $field): int {
        $entries = Entry::find()
            ->section($section->handle)
            ->all();

        $resolved = 0;

        foreach ($entries as $entry) {
            $links = $entry->getFieldValue($field->handle);

            if ($links?->getUrl()) {
                $resolved++;
            }
        }

        return $resolved;
    });

    expect($profile['queries'])->toBeGreaterThan(0);
    expect($profile['resultSize'])->toBe(10);
    expect($profile['durationMs'])->toBeGreaterThanOrEqual(0.0);

    fwrite(STDERR, "\nHyper perf smoke profile: " . json_encode($profile, JSON_PRETTY_PRINT) . "\n");
})->group('perf');

it('captures entry-backed link hydration costs', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $targets = HyperFixtureFactory::entries(5, $section);

    foreach ($targets as $target) {
        HyperFixtureFactory::entryWithLinks(
            $section,
            [HyperFixtureFactory::entryLinkPayload($target, $target->title)],
            'Nav item for ' . $target->title,
        );
    }

    $profiles = [
        'read-urls-only' => QueryProfiler::profile(function() use ($section, $field): int {
            $count = 0;

            foreach (Entry::find()->section($section->handle)->all() as $entry) {
                if ($entry->getFieldValue($field->handle)?->getUrl()) {
                    $count++;
                }
            }

            return $count;
        }),
        'touch-linked-elements' => QueryProfiler::profile(function() use ($section, $field): int {
            $linked = 0;

            foreach (Entry::find()->section($section->handle)->all() as $entry) {
                $links = $entry->getFieldValue($field->handle);

                foreach ($links ?? [] as $link) {
                    if (method_exists($link, 'getElement') && $link->getElement()) {
                        $linked++;
                    }
                }
            }

            return $linked;
        }),
    ];

    expect($profiles['read-urls-only']['queries'])->toBeGreaterThan(0);

    fwrite(STDERR, "\nHyper entry-link perf profile: " . json_encode($profiles, JSON_PRETTY_PRINT) . "\n");
})->group('perf');
