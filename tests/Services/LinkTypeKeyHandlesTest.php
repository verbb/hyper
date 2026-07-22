<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\base\Link;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkInstance;
use verbb\hyper\services\LinkTypeConfigs;

it('derives short, author-owned type keys from the class name', function() {
    expect(Url::typeKey())->toBe('url');
    expect(EntryLink::typeKey())->toBe('entry');
});

it('seeds stock link types with short type-key handles', function() {
    $stock = Hyper::$plugin->getLinkTypeConfigs()->createStockSerializedLinkTypes();

    $handles = array_column($stock, 'handle', 'type');

    expect($handles[Url::class] ?? null)->toBe('url');
    expect($handles[EntryLink::class] ?? null)->toBe('entry');
});

it('normalizes early v3 stock handles without changing custom instances', function() {
    $normalized = LinkTypeConfigs::normalizeLegacyStockHandles([
        [
            'type' => Url::class,
            'handle' => 'default-verbb-hyper-links-url',
            'isCustom' => false,
        ],
        [
            'type' => Url::class,
            'handle' => 'campaignUrl',
            'isCustom' => true,
        ],
    ]);

    expect($normalized[0]['handle'])->toBe('url');
    expect($normalized[1]['handle'])->toBe('campaignUrl');
});

it('produces clean graphql type names from the short handle fallback', function() {
    $field = HyperFixtureFactory::hyperField();
    $linkType = Hyper::$plugin->getLinks()->createLink(Url::class);
    $linkType->field = $field;
    $linkType->handle = null; // force the type-key fallback

    // Clean `…_Url_LinkType` rather than `…_DefaultVerbbHyperLinksUrl_LinkType`.
    expect(Link::gqlTypeNameByContext($linkType))->toBe($field->handle . '_Url_LinkType');
});

it('resolves programmatic payloads by short type key', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class, EntryLink::class]]);

    // `type` as the short key (not the FQCN) resolves to the field's configured handle.
    $fromKey = LinkInstance::fromSerialized([
        'type' => 'entry',
        'linkValue' => [1],
    ], $field);

    expect($fromKey->linkTypeHandle)->toBe('entry');

    // Handle-only payloads keep working too.
    $fromHandle = LinkInstance::fromSerialized([
        'handle' => 'url',
        'linkValue' => 'https://example.test/key',
    ], $field);

    expect($fromHandle->linkTypeHandle)->toBe('url');
});

it('still resolves legacy default-<fqcn> handles from stored content', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);

    // Content saved before short type keys used the verbose default handle.
    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'linkTypeHandle' => 'default-verbb-hyper-links-url',
        'linkValue' => 'https://example.test/legacy',
    ]);

    expect($link)->not->toBeNull();
    expect($link->getLinkUrl())->toBe('https://example.test/legacy');
});
