<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Url;

it('keeps a configured fixed URL through tampered content and ordinary owner saves', function() {
    $url = new Url(['defaultLinkValue' => 'https://example.test/fixed', 'fixedLinkValue' => true]);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::entryWithLinks(F::entrySection($field), [[
        'linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/changed', 'linkText' => 'Author label',
    ]]);
    $link = $owner->getFieldValue($field->handle)->first();
    expect($link->getUrl())->toBe('https://example.test/fixed');
    $link->linkValue = 'https://example.test/programmatic-change';
    expect($link->getUrl())->toBe('https://example.test/fixed');
    expect($link->getSerializedValues()['linkValue'])->toBe('https://example.test/fixed');
    expect($link->getText())->toBe('Author label');
    $reload = \craft\elements\Entry::find()->id($owner->id)->siteId($owner->siteId)->one();
    expect($reload->getFieldValue($field->handle)->first()->linkValue)->toBe('https://example.test/fixed');
});
