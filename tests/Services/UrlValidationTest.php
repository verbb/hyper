<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;

it('accepts hash-only url link values', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->setAttributes(['linkValue' => '#section'], false);

    expect($link->validate())->toBeTrue();
    expect($link->getLinkUrl())->toBe('#section');
});

it('rejects non-allowlisted uri schemes on url link values by default', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->setAttributes(['linkValue' => 'slack://channel?team=T123'], false);

    expect($link->validate())->toBeFalse();
});

it('accepts extra uri schemes when configured on plugin settings', function() {
    $settings = Hyper::$plugin->getSettings();
    $previous = $settings->allowedUriSchemes;
    $settings->allowedUriSchemes = ['slack'];

    try {
        $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
        $link = Hyper::$plugin->getLinks()->createLink(Url::class);
        $link->field = $field;
        $link->setAttributes(['linkValue' => 'slack://channel?team=T123'], false);

        expect($link->validate())->toBeTrue();
        expect($link->getLinkUrl())->toBe('slack://channel?team=T123');
    } finally {
        $settings->allowedUriSchemes = $previous;
    }
});

it('does not require link text when the link value is empty but link text is marked required', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->isFieldRequired = false;
    $link->setScenario(verbb\hyper\base\Link::SCENARIO_LIVE);
    $link->setFieldLayout(Url::getDefaultFieldLayout());

    $layout = $link->getFieldLayout();
    $linkTextField = $layout->getField('linkText');
    $linkTextField->required = true;
    $link->setFieldLayout($layout);

    $link->setAttributes([
        'linkValue' => null,
        'linkText' => null,
    ], false);

    expect($link->validate())->toBeTrue();
});

it('requires link text when the link value is present and link text is marked required', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->setScenario(verbb\hyper\base\Link::SCENARIO_LIVE);
    $link->setFieldLayout(Url::getDefaultFieldLayout());

    $layout = $link->getFieldLayout();
    $linkTextField = $layout->getField('linkText');
    $linkTextField->required = true;
    $link->setFieldLayout($layout);

    $link->setAttributes([
        'linkValue' => 'https://example.test/labeled',
        'linkText' => null,
    ], false);

    expect($link->validate())->toBeFalse();
    expect($link->getErrors('linkText'))->not->toBeEmpty();
});
