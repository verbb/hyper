<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Email;
use verbb\hyper\links\Entry;
use verbb\hyper\links\Phone;
use verbb\hyper\links\Site;
use verbb\hyper\links\Url;

it('ignores unavailable sites in options while retaining valid site destinations', function() {
    $site = Craft::$app->sites->primarySite;
    $type = new Site(['sites' => ['unavailable-site', $site->uid]]);
    expect(array_column($type->getSiteOptions(), 'value'))->toBe(['', $site->uid]);
    $field = F::hyperField(['linkTypes' => [Site::class]]);
    $link = Hyper::$plugin->links->createLinkFromSerialized($field, ['linkTypeHandle' => 'site', 'linkValue' => $site->uid, 'urlSuffix' => '#section']);
    expect($link->getUrl())->toBe($site->getBaseUrl() . '#section');
});
