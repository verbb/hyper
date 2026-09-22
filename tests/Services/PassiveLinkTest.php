<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Passive;
use verbb\hyper\models\LinkInstance;

it('keeps passive label content while treating its destination as empty', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Passive::class],
    ]);

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, HyperFixtureFactory::passiveLinkPayload('Shop'));

    expect($link->isEmpty())->toBeTrue();
    expect(Passive::isInstanceEmpty($link->toInstance()))->toBeFalse();
});

it('treats blank passive links as empty', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Passive::class],
    ]);

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, HyperFixtureFactory::passiveLinkPayload(null));

    expect($link->isEmpty())->toBeTrue();
    expect(Passive::isInstanceEmpty(LinkInstance::fromSerialized([], $field)))->toBeTrue();
});

it('returns passive link text without a URL', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Passive::class],
    ]);

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, HyperFixtureFactory::passiveLinkPayload('Shop'));

    expect($link->getUrl())->toBeNull();
    expect($link->getText())->toBe('Shop');
});
