<?php

declare(strict_types=1);

use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use Tests\Support\Fixtures\HyperFixtureFactory;

it('returns Link Text layout defaultValue when linkText is empty', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $layout = Url::getDefaultFieldLayout();
    $linkText = $layout->getField('linkText');
    expect($linkText)->toBeInstanceOf(LinkTextField::class);

    $linkText->defaultValue = 'Learn More';
    $link->setFieldLayout($layout);
    $link->linkText = null;

    expect($link->getLinkText())->toBe('Learn More');
});

it('prefers explicit linkText over layout defaultValue', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $layout = Url::getDefaultFieldLayout();
    $linkText = $layout->getField('linkText');
    $linkText->defaultValue = 'Learn More';
    $link->setFieldLayout($layout);
    $link->linkText = 'Custom CTA';

    expect($link->getLinkText())->toBe('Custom CTA');
});

it('validates linkText against layout maxlength', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $layout = Url::getDefaultFieldLayout();
    $linkText = $layout->getField('linkText');
    expect($linkText)->toBeInstanceOf(LinkTextField::class);
    $linkText->maxlength = 5;

    $link->setFieldLayout($layout);
    $link->linkValue = 'https://example.test';
    $link->linkText = 'Too long for limit';

    expect($link->validate())->toBeFalse();
    expect($link->getErrors('linkText'))->not->toBeEmpty();
});

it('seeds createDefaultContentLink from layout defaultValue', function() {
    $layout = Url::getDefaultFieldLayout();
    $linkText = $layout->getField('linkText');
    expect($linkText)->toBeInstanceOf(LinkTextField::class);
    $linkText->defaultValue = 'Learn More';

    $urlLink = Hyper::$plugin->getLinks()->createLink(Url::class);
    $urlLink->enabled = true;
    $urlLink->handle = $urlLink->handle ?: 'default-url';
    $urlLink->layoutUid = $urlLink->layoutUid ?: \craft\helpers\StringHelper::UUID();
    $urlLink->setFieldLayout($layout);

    $field = HyperFixtureFactory::hyperFieldWithLinkTypes([$urlLink->getSettingsConfigForDb()]);

    $default = Hyper::$plugin->getLinks()->createDefaultContentLink($field, $field->defaultLinkType);

    expect($default)->not->toBeNull();
    expect($default->linkText)->toBe('Learn More');
});
