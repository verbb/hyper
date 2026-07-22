<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\links\Site;

it('includes an empty placeholder as the first Site link option', function() {
    $field = HyperFixtureFactory::hyperField();
    $link = new Site();
    $link->field = $field;
    $link->sites = '*';
    $link->enabled = true;

    $options = $link->getSiteOptions();

    expect($options)->not->toBeEmpty();
    expect($options[0]['value'])->toBe('');
    expect($options[0]['label'])->toBe(Craft::t('hyper', 'Select a site'));

    $siteValues = array_column(array_slice($options, 1), 'value');
    expect($siteValues)->not->toContain('');
});

it('keeps an unset Site linkValue empty when the placeholder is selected', function() {
    $field = HyperFixtureFactory::hyperField();
    $link = new Site();
    $link->field = $field;
    $link->linkValue = null;

    expect($link->getLinkSite())->toBeNull();
    expect($link->getLinkUrl())->toBeNull();
});
