<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('looks up a disabled destination once when a template reads the collection repeatedly', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Retired page');
    $owner = F::entryWithLinks($section, [F::entryLinkPayload($target, 'Retired')], 'Owner');

    $target->enabled = false;
    expect(Craft::$app->getElements()->saveElement($target))->toBeTrue();

    Hyper::$plugin->getLinkRelations()->resetRequestState();
    $links = Entry::find()->id($owner->id)->status(null)->one()->getFieldValue($field->handle);

    // The first read resolves the destination; the same template checks repeat it.
    expect($links->getUrl())->toBeNull();

    $repeat = QueryProfiler::profile(function() use ($links): int {
        $reads = 0;

        foreach (range(1, 5) as $i) {
            $links->getUrl();
            $links->first();
            count($links);
            $reads++;
        }

        return $reads;
    });

    expect($repeat['queries'])->toBe(0)
        ->and(count($links))->toBe(0)
        ->and($links->first())->toBeNull();

    // Enabling the destination is picked up by a freshly loaded owner.
    $target->enabled = true;
    expect(Craft::$app->getElements()->saveElement($target))->toBeTrue();
    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $fresh = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle);

    expect($fresh->getUrl())->toBe($target->getUrl());
})->group('perf');

it('does not query link relations for elements without a Hyper field', function() {
    $plain = F::entrySection();
    F::plainEntry($plain, 'No links here');
    F::plainEntry($plain, 'Nor here');

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $profile = QueryProfiler::profile(fn() => Entry::find()->section($plain->handle)->all());
    $relationQueries = array_filter(
        array_keys($profile['patterns']),
        static fn(string $sql): bool => str_contains($sql, 'hyper_links'),
    );

    expect($profile['resultSize'])->toBe(2)
        ->and($relationQueries)->toBe([]);
})->group('perf');
