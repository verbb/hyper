<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\links\Entry as EntryLink;

it('clears a cached element when the link target is removed in memory', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::plainEntry($section, 'Link target');

    $owner = HyperFixtureFactory::plainEntry(
        $section,
        'Link owner',
        [
            $field->handle => [
                HyperFixtureFactory::entryLinkPayload($target, 'Read more'),
            ],
        ],
    );

    $link = $owner->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getElement()?->id)->toBe($target->id);

    $link->linkValue = null;

    expect($link->getElement())->toBeNull();
    expect($link->hasElement())->toBeFalse();
});

it('clears a cached element when setAttributes receives an empty selection', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::plainEntry($section, 'Link target');

    $owner = HyperFixtureFactory::plainEntry(
        $section,
        'Link owner',
        [
            $field->handle => [
                HyperFixtureFactory::entryLinkPayload($target, 'Read more'),
            ],
        ],
    );

    $link = $owner->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getElement()?->id)->toBe($target->id);

    $link->setAttributes(['linkValue' => []], false);

    expect($link->linkValue)->toBeNull();
    expect($link->getElement())->toBeNull();
});

it('persists a cleared element reference after save', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::plainEntry($section, 'Link target');

    $owner = HyperFixtureFactory::plainEntry(
        $section,
        'Link owner',
        [
            $field->handle => [
                HyperFixtureFactory::entryLinkPayload($target, 'Read more'),
            ],
        ],
    );

    $links = $owner->getFieldValue($field->handle);
    $link = $links->getLinks()[0];

    expect($link->getElement()?->id)->toBe($target->id);

    $link->setAttributes(['linkValue' => []], false);
    $owner->setFieldValue($field->handle, $links);

    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();

    $owner = Entry::find()->id($owner->id)->status(null)->one();
    $link = $owner->getFieldValue($field->handle)->getLinks()[0];

    expect($link->linkValue)->toBeNull();
    expect($link->getElement())->toBeNull();
});
