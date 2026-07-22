<?php

declare(strict_types=1);

use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkInstance;

it('mints and persists a durable link uid on serialize', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createDefaultContentLink($field);
    expect($link)->not->toBeNull();

    $link->linkValue = 'https://example.com/clipboard';
    $link->linkText = 'Clipboard';

    $serialized = $link->getSerializedValues();
    expect($serialized)->toHaveKey('uid');
    expect($serialized['uid'])->toBeString()->not->toBeEmpty();

    $uid = $serialized['uid'];
    $again = $link->getSerializedValues();
    expect($again['uid'])->toBe($uid);
});

it('round-trips uid through LinkInstance serialization', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $uid = StringHelper::UUID();

    $instance = LinkInstance::fromSerialized([
        'linkTypeHandle' => 'default-' . StringHelper::toKebabCase(Url::class),
        'uid' => $uid,
        'linkValue' => 'https://example.com',
        'linkText' => 'Example',
    ], $field);

    expect($instance->uid)->toBe($uid);
    expect($instance->toSerialized()['uid'] ?? null)->toBe($uid);
});
