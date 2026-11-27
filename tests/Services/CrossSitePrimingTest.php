<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\base\ElementLink;
use verbb\hyper\Hyper;

it('primes only the target sites that links point at when a batch spans sites', function() {
    $count = 8;
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField(['multipleLinks' => true]);
    $ownerSection = F::entrySection($field);
    $targetSection = F::entrySection(null, null, true);
    $targets = [];

    for ($i = 0; $i < $count; $i++) {
        $targets[] = F::plainEntry($targetSection, 'Cross-site target ' . $i);
    }

    // Every target exists on both sites, so loading all IDs on all sites finds a copy of each.
    $localOwner = F::entryWithLinkPayloads($ownerSection, array_map(static fn($target): array => F::entryLinkPayload($target), $targets), 'Local links');
    $crossSiteOwner = F::entryWithLinkPayloads($ownerSection, [F::entryLinkPayload($targets[0]) + ['linkSiteId' => $secondary->id]], 'Cross-site link');

    $relations = Hyper::$plugin->getLinkRelations();
    $relations->resetRequestState();
    $relations->registerOwner($localOwner->id, $primary->id);
    $relations->registerOwner($crossSiteOwner->id, $primary->id);
    $profile = QueryProfiler::profile(static fn() => $relations->primePendingOwners());

    $hydrated = array_keys((new ReflectionProperty($relations, '_hydratedElements'))->getValue($relations));
    $expected = [
        ...array_map(static fn($target): string => $target->id . ':' . $primary->id, $targets),
        $targets[0]->id . ':' . $secondary->id,
    ];

    fwrite(STDERR, "\nCross-site priming: " . json_encode(['pairsNeeded' => count($expected), 'hydrated' => count($hydrated), 'queries' => $profile['queries'], 'durationMs' => $profile['durationMs']]) . "\n");

    // One link to another site must not load every other target on that site too.
    expect($hydrated)->toEqualCanonicalizing($expected)
        ->and($relations->getPrimedElement($targets[1]->id, $secondary->id))->toBeNull();

    // Both links still read their primed element on the site they point at.
    $readSites = [];

    foreach ([$localOwner, $crossSiteOwner] as $owner) {
        foreach (Craft::$app->getEntries()->getEntryById($owner->id, $primary->id)->getFieldValue($field->handle) as $link) {
            if ($link instanceof ElementLink) {
                $readSites[] = $link->getElement()?->siteId;
            }
        }
    }

    expect($readSites)->toBe([...array_fill(0, $count, $primary->id), $secondary->id]);
});
