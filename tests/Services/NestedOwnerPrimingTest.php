<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;

it('registers populated entry owners for priming', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryTargets(1, $section)[0];
    $owner = HyperFixtureFactory::entryWithLinkPayloads(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target)],
        'Owner entry',
    );

    Hyper::$plugin->getLinkRelations()->resetRequestState();
    Hyper::$plugin->getLinkRelations()->registerElementForPriming($owner);
    Hyper::$plugin->getLinkRelations()->primePendingOwners();

    $links = $owner->getFieldValue($field->handle);
    $link = $links->getLinks()[0];

    expect($link->getElement())->not->toBeNull();
    expect($link->getElement()->id)->toBe($target->id);
});

it('parses hyper linkedElements with paths during query prepare', function() {
    $field = HyperFixtureFactory::hyperField();
    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $query = Entry::find();
    $query->with = [$field->handle . '.linkedElements'];

    Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);

    expect($query->with)->toBe([]);
});

it('registers linked element with paths from nested-looking hyper tokens', function() {
    $field = HyperFixtureFactory::hyperField();
    $related = HyperFixtureFactory::entriesField();
    $token = $field->handle . '.linkedElements.' . $related->handle;

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $query = Entry::find();
    $query->with = [$token];

    Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);

    expect($query->with)->toBe([]);
    expect(Hyper::$plugin->getLinkRelations()->getLinkedElementWithForField($field->id))->toBe([$related->handle]);
});
