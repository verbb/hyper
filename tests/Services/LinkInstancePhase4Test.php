<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\base\Link;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkInstance;

it('treats links with link text but no target as non-empty', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target')],
        'Target',
    );

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'type' => verbb\hyper\links\Entry::class,
        'handle' => 'default-verbb-hyper-links-entry',
        'linkText' => 'About us',
        'linkValue' => [],
    ]);

    expect($link)->not->toBeNull();
    expect($link->isEmpty())->toBeFalse();
    expect($field->isValueEmpty(new LinkCollection($field, [[
        'type' => verbb\hyper\links\Entry::class,
        'handle' => 'default-verbb-hyper-links-entry',
        'linkText' => 'About us',
    ]]), $target))->toBeFalse();
});

it('serializes saved links with linkTypeHandle for v3 content', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/saved', 'Saved link')],
    );

    $collection = $entry->getFieldValue($field->handle);
    $serialized = $field->serializeValue($collection, $entry);

    expect($serialized[0]['linkTypeHandle'] ?? null)->toBe('url');
    expect($serialized[0]['type'] ?? null)->toBeNull();
    expect($serialized[0]['handle'] ?? null)->toBeNull();
});

it('hydrates v3 linkTypeHandle-only payloads', function() {
    $field = HyperFixtureFactory::hyperField();
    $collection = new LinkCollection($field, [[
        'linkTypeHandle' => 'default-verbb-hyper-links-url',
        'linkValue' => 'https://example.test/v3',
        'linkText' => 'V3 link',
    ]]);

    expect($collection->isEmpty())->toBeFalse();
    expect($collection->getUrl())->toBe('https://example.test/v3');
});

it('resolves url links from instances without constructing full element queries', function() {
    $instance = new LinkInstance();
    $instance->linkTypeHandle = 'default-verbb-hyper-links-url';
    $instance->linkValue = 'https://example.test/from-instance';

    expect(Url::resolveUrlFromInstance($instance))->toBe('https://example.test/from-instance');
    expect(Link::isInstanceEmpty($instance))->toBeFalse();
});

it('enables custom link types on hyper fields', function() {
    $field = HyperFixtureFactory::hyperField();

    expect($field->hasCustomLinkTypes())->toBeTrue();

    $field->useLinkTypeConfig('default');
    expect($field->hasCustomLinkTypes())->toBeFalse();
    expect($field->getLinkTypes())->not->toBeEmpty();

    $field->enableCustomLinkTypes();
    expect($field->hasCustomLinkTypes())->toBeTrue();
    expect($field->getLinkTypeDefinitionByHandle('url')
        ?? $field->getLinkTypes()[0] ?? null)->not->toBeNull();
});
