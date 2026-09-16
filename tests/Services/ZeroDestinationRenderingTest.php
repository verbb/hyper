<?php

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Custom;

it('renders a saved Custom link to the zero relative path', function() {
    $field = F::hyperField(['linkTypes' => [Custom::class]]);
    $section = F::entrySection($field);
    $owner = F::plainEntry($section, 'Zero path owner', [
        $field->handle => [[
            'linkTypeHandle' => 'custom',
            'linkValue' => '0',
            'linkText' => 'Page zero',
        ]],
    ]);
    $saved = Entry::find()->id($owner->id)->siteId($owner->siteId)->status(null)->one();
    $link = $saved->getFieldValue($field->handle)->first();

    expect($link->validate())->toBeTrue();
    expect($link->getUrl())->toBe('0');
    expect($link->getText())->toBe('Page zero');
    expect((string)$link->getLink())->toBe('<a href="0">Page zero</a>');
    expect($link->getLinkAttributes()['href'] ?? null)->toBe('0');
});
