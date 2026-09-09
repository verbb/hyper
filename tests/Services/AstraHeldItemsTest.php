<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\helpers\UrlSafety;
use verbb\hyper\Hyper;
use verbb\hyper\links\MissingLink;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkTypeConfig;
use verbb\hyper\services\LinkTypeConfigs;

it('rejects private and metadata addresses for embed hosts', function() {
    expect(UrlSafety::isPublicIp('127.0.0.1'))->toBeFalse();
    expect(UrlSafety::isPublicIp('10.0.0.5'))->toBeFalse();
    expect(UrlSafety::isPublicIp('192.168.1.10'))->toBeFalse();
    expect(UrlSafety::isPublicIp('169.254.169.254'))->toBeFalse();
    expect(UrlSafety::isPublicIp('::1'))->toBeFalse();
    expect(UrlSafety::isPublicFetchHost('localhost'))->toBeFalse();
    expect(UrlSafety::isPublicFetchHost('metadata.google.internal'))->toBeFalse();
});

it('fails closed when resolving an embed URL that targets a private host', function() {
    $error = null;
    $resolved = UrlSafety::resolvePublicEmbedUrl('http://127.0.0.1/', 2, $error);

    expect($resolved)->toBeNull()
        ->and($error)->toBe('Embed URL host is not allowed.');
});

it('dual-reads link type config handles and rewrites field settings to UIDs', function() {
    $configs = Hyper::$plugin->getLinkTypeConfigs();
    $configs->ensureConfigsExist();

    $named = new LinkTypeConfig([
        'name' => 'Marketing',
        'handle' => 'marketing-' . substr(md5((string)microtime(true)), 0, 8),
        'linkTypes' => $configs->createStockSerializedLinkTypes(),
    ]);
    $configs->saveConfig($named);

    try {
        $byHandle = $configs->resolveConfig($named->handle);
        $byUid = $configs->resolveConfig($named->uid);

        expect($byHandle->uid)->toBe($named->uid)
            ->and($byUid->handle)->toBe($named->handle)
            ->and($configs->normalizeFieldConfigRef($named->handle))->toBe($named->uid);

        // Unsaved field shell — constructor dual-reads handle → uid without requiring custom linkTypes.
        $field = new \verbb\hyper\fields\HyperField([
            'name' => 'UID Ref Field',
            'handle' => HyperFixtureFactory::handle('hyperUidRef'),
            'linkTypeConfig' => $named->handle,
            'linkTypes' => [],
        ]);

        expect($field->linkTypeConfig)->toBe($named->uid)
            ->and($field->getSettings()['linkTypeConfig'])->toBe($named->uid)
            ->and($field->hasCustomLinkTypes())->toBeFalse();
    } finally {
        $configs->deleteConfig($named->uid);
    }
});

it('retains unsupported MissingLink payloads through normalize and serialize', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);

    $collection = $field->normalizeValue([
        [
            'linkTypeHandle' => 'gone-type',
            'linkValue' => 'https://example.test/retained',
            'linkText' => 'Keep me',
            'uid' => '11111111-1111-4111-8111-111111111111',
        ],
    ], null);

    $links = $collection->getLinks();

    expect($links)->toHaveCount(1)
        ->and($links[0])->toBeInstanceOf(MissingLink::class);

    $serialized = $collection->serializeValues();

    expect($serialized[0]['linkTypeHandle'] ?? null)->toBe('gone-type')
        ->and($serialized[0]['linkValue'] ?? null)->toBe('https://example.test/retained')
        ->and($serialized[0]['linkText'] ?? null)->toBe('Keep me');
});

it('forces embed curl clients to disable opaque redirect following', function() {
    $settings = Hyper::$plugin->getSettings();
    $previous = $settings->embedClientSettings;
    $settings->embedClientSettings = [
        'follow_location' => true,
        'max_redirs' => 9,
    ];

    try {
        $client = $settings->getEmbedClientSettings();

        expect($client['follow_location'])->toBeFalse()
            ->and($client['max_redirs'])->toBe(0);
    } finally {
        $settings->embedClientSettings = $previous;
    }
});
