<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

it('boots Hyper and can create a field with URL links', function() {
    expect(Hyper::$plugin)->not->toBeNull();

    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/about', 'About')],
        'About Page',
    );

    $links = $entry->getFieldValue($field->handle);

    expect($links)->toBeInstanceOf(LinkCollection::class);
    expect($links->getUrl())->toBe('https://example.test/about');
    expect($links->getText())->toBe('About');
});
