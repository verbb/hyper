<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Email;
use verbb\hyper\links\Entry;
use verbb\hyper\links\Phone;
use verbb\hyper\links\Site;
use verbb\hyper\links\Url;

it('does not render a suffix when the underlying destination is absent', function(string $type, mixed $value) {
    $field = F::hyperField(['linkTypes' => [Url::class, Entry::class, Site::class, Phone::class, Email::class]]);
    $link = Hyper::$plugin->links->createLinkFromSerialized($field, [
        'linkTypeHandle' => $type, 'linkValue' => $value, 'linkText' => 'Retained label', 'urlSuffix' => '#section',
    ]);
    expect($link->getLinkUrl())->toBeNull();
    expect($link->getUrl())->toBeNull();
    expect($link->getLink())->toBeNull();
    expect($link->getSerializedValues()['urlSuffix'])->toBe('#section');
})->with([
    ['url', null], ['entry', [999999999]], ['site', 'nonexistent-site'], ['phone', null], ['email', null],
]);

it('keeps valid suffixes and a zero phone destination', function() {
    $field = F::hyperField(['linkTypes' => [Url::class, Phone::class]]);
    $url = Hyper::$plugin->links->createLinkFromSerialized($field, ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test', 'urlSuffix' => '#section']);
    expect($url->getUrl())->toBe('https://example.test#section');
    $phone = Hyper::$plugin->links->createLinkFromSerialized($field, ['linkTypeHandle' => 'phone', 'linkValue' => '0']);
    expect($phone->getUrl())->toBe('tel:0');
});

it('ignores unavailable sites in options while retaining valid site destinations', function() {
    $site = Craft::$app->sites->primarySite;
    $type = new Site(['sites' => ['unavailable-site', $site->uid]]);
    expect(array_column($type->getSiteOptions(), 'value'))->toBe(['', $site->uid]);
    $field = F::hyperField(['linkTypes' => [Site::class]]);
    $link = Hyper::$plugin->links->createLinkFromSerialized($field, ['linkTypeHandle' => 'site', 'linkValue' => $site->uid, 'urlSuffix' => '#section']);
    expect($link->getUrl())->toBe($site->getBaseUrl() . '#section');
});

it('keeps disabled or scheduled selections visible in the editor without rendering a suffix', function(string $state) {
    $field = F::hyperField(['linkTypes' => [Entry::class]]);
    $target = F::plainEntry(F::entrySection(), 'Unavailable target');
    if ($state === 'disabled') {
        $target->enabled = false;
    } else {
        $target->postDate = new DateTime('+1 day');
    }
    expect(Craft::$app->elements->saveElement($target))->toBeTrue();
    Hyper::$plugin->linkRelations->resetRequestState();
    $link = Hyper::$plugin->links->createLinkFromSerialized($field, F::entryLinkPayload($target) + ['urlSuffix' => '#section']);
    expect($link->getElements()[0]->id ?? null)->toBe($target->id);
    expect($link->getUrl())->toBeNull();
})->with(['disabled', 'scheduled']);
