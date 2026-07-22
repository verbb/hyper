<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Category;
use verbb\hyper\links\Email;
use verbb\hyper\links\Entry;
use verbb\hyper\links\Url;
use verbb\hyper\migrations\MigrateEntrifyCategories;
use verbb\hyper\migrations\MigrateLinkContent;
use verbb\hyper\migrations\MigrateTypedLinkContent;

it('registers oembed in the migrations registry', function() {
    $sources = Hyper::$plugin->getMigrations()->getSources();

    expect($sources)->toHaveKey('oembed');
    expect($sources['oembed']['consoleCommand'])->toBe('hyper/migrate/oembed');
});

it('converts typed link vizy-shaped payloads with value keys', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Entry::class, Url::class]]);
    $migrator = new MigrateTypedLinkContent();

    $converted = $migrator->convertModel($field, [
        'type' => 'entry',
        'value' => '67',
        'customText' => 'Button',
        'target' => '_blank',
        'customQuery' => '?utm=1',
    ]);

    expect($converted)->toBeArray()->not->toBeEmpty();
    expect($converted[0]['linkValue'] ?? null)->toBe(67);
    expect($converted[0]['linkText'] ?? null)->toBe('Button');
    expect($converted[0]['newWindow'] ?? null)->toBeTrue();
});

it('converts typed link classic linkedUrl payloads', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $migrator = new MigrateTypedLinkContent();

    $converted = $migrator->convertModel($field, [
        'type' => 'url',
        'linkedUrl' => 'https://example.com/?region=au&foo=1',
        'payload' => json_encode(['customText' => 'Go', 'target' => '']),
    ]);

    expect($converted)->toBeArray()->not->toBeEmpty();
    expect($converted[0]['linkValue'] ?? null)->toBe('https://example.com/?region=au&foo=1');
});

it('restores corrupted region query strings from html entity decode', function() {
    $migrator = new MigrateTypedLinkContent();
    $method = new ReflectionMethod($migrator, 'preserveUrlEncoding');
    $method->setAccessible(true);

    expect($method->invoke($migrator, 'https://example.com/?®ion=au'))
        ->toBe('https://example.com/?region=au');
    expect($method->invoke($migrator, 'https://example.com/?foo=1®ion=au'))
        ->toBe('https://example.com/?foo=1&region=au');
});

it('remaps category links to entries for entrification', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Category::class, Entry::class]]);
    $categoryHandle = null;
    $entryHandle = null;

    foreach ($field->getLinkTypes() as $lt) {
        if ($lt instanceof Category) {
            $categoryHandle = $lt->handle;
        }
        if ($lt instanceof Entry) {
            $entryHandle = $lt->handle;
        }
    }

    $migrator = new MigrateEntrifyCategories();

    $converted = $migrator->convertModel($field, [[
        'linkTypeHandle' => $categoryHandle,
        'handle' => $categoryHandle,
        'type' => Category::class,
        'linkValue' => 10,
        'linkSiteId' => 1,
    ]]);

    expect($converted)->toBeArray();
    expect($converted[0]['linkValue'])->toBe(10); // Craft entrify keeps the same ID
    expect($converted[0]['linkTypeHandle'])->toBe($entryHandle);
    expect($converted[0]['type'])->toBe(Entry::class);
});

it('converts flipbox content via migrationData identifier map (#253)', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class, Entry::class]]);
    $urlHandle = null;

    foreach ($field->getLinkTypes() as $lt) {
        if ($lt instanceof Url) {
            $urlHandle = $lt->handle;
            break;
        }
    }

    $field->migrationData = [
        'urlTypeKey' => [
            'handle' => $urlHandle,
            'class' => Url::class,
        ],
    ];

    $migrator = new MigrateLinkContent();

    $converted = $migrator->convertModel($field, [
        'identifier' => 'urlTypeKey',
        'url' => 'https://example.com/nested',
        'overrideText' => 'Go',
        'target' => '_blank',
    ]);

    expect($converted)->toBeArray()->not->toBeEmpty();
    expect($converted[0]['linkValue'] ?? null)->toBe('https://example.com/nested');
    expect($converted[0]['linkText'] ?? null)->toBe('Go');
    expect($converted[0]['newWindow'] ?? null)->toBeTrue();
    expect($converted[0]['linkTypeHandle'] ?? null)->toBe($urlHandle);
});

it('falls back to content shape when flipbox migrationData misses identifier (#253)', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class, Entry::class, Email::class]]);
    $field->migrationData = []; // nested field lost map

    $migrator = new MigrateLinkContent();

    $converted = $migrator->convertModel($field, [
        'identifier' => 'orphan-key',
        'url' => 'https://example.com/fallback',
        'overrideText' => 'Fallback',
        'target' => '',
    ]);

    expect($converted)->toBeArray()->not->toBeEmpty();
    expect($converted[0]['linkValue'] ?? null)->toBe('https://example.com/fallback');
    expect($converted[0]['linkText'] ?? null)->toBe('Fallback');
});

it('skips already-migrated Hyper collections for flipbox content (#253)', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $migrator = new MigrateLinkContent();

    $converted = $migrator->convertModel($field, [[
        'linkTypeHandle' => 'url',
        'linkValue' => 'https://example.com',
    ]]);

    expect($converted)->toBeNull();
});
