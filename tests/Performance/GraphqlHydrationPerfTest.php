<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\NavLinkPerfScenario;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('resolves many entry links without element cache queries after priming', function() {
    $scenario = NavLinkPerfScenario::singleOwnerWithEntryLinks(20);
    $field = $scenario['field'];

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $profile = QueryProfiler::profile(function() use ($scenario, $field): int {
        $entries = Entry::find()
            ->section($scenario['section']->handle)
            ->id($scenario['owners'][0]->id)
            ->all();

        $resolved = 0;

        foreach ($entries as $entry) {
            $links = $entry->getFieldValue($field->handle);

            foreach ($links->getLinks() as $link) {
                if ($link->getUrl() && $link->getLinkText()) {
                    $resolved++;
                }
            }
        }

        return $resolved;
    });

    expect($profile['resultSize'])->toBe(20);
    expect(NavLinkPerfScenario::countElementCacheQueries($profile))->toBe(0);
    expect($profile['duplicatePatterns'])->toBeLessThan(5);
})->group('perf');
