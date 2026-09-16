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

it('preserves a zero label for URL element embed site and passive links', function() {
    $field = F::hyperFieldWithLinkTypes(array_map(fn($class) => F::linkTypeConfig($class), [
        Url::class, \verbb\hyper\links\Entry::class, Embed::class, \verbb\hyper\links\Site::class, Passive::class,
    ]));
    $target = F::plainEntry(F::entrySection(), 'Fallback title');
    $payloads = [
        ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/zero'],
        ['linkTypeHandle' => 'entry', 'linkValue' => $target->id],
        ['linkTypeHandle' => 'embed', 'linkValue' => ['url' => 'https://example.test/zero', 'title' => 'Fallback title']],
        ['linkTypeHandle' => 'site', 'linkValue' => Craft::$app->sites->primarySite->uid],
        ['linkTypeHandle' => 'passive'],
    ];
    foreach ($payloads as $payload) {
        $link = Hyper::$plugin->links->createLinkFromSerialized($field, $payload + ['linkText' => '0']);
        expect($link->getText())->toBe('0');
        expect($link->isEmpty())->toBeFalse();
        expect($field->getPreviewHtml($field->normalizeValue([$link]), $target))->toBe('0');
    }
});
