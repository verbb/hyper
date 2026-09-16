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

it('uses configured labels before Site and Embed fallback labels', function(string $class, ?string $authored, string $default, string $expected) {
    $link = new $class();
    $layout = $class::getDefaultFieldLayout();
    $layout->getField('linkText')->defaultValue = $default;
    $link->setFieldLayout($layout);
    $link->linkText = $authored;
    $link->linkValue = $class === \verbb\hyper\links\Site::class
        ? Craft::$app->sites->getPrimarySite()->uid
        : ['url' => 'https://example.test/video', 'title' => 'Provider label'];

    expect($link->getLinkText())->toBe($expected);
})->with([\verbb\hyper\links\Site::class, \verbb\hyper\links\Embed::class])->with([
    'null uses default' => [null, 'Configured label', 'Configured label'],
    'empty uses default' => ['', 'Configured label', 'Configured label'],
    'zero default' => [null, '0', '0'],
    'authored wins' => ['Authored label', 'Configured label', 'Authored label'],
    'authored zero wins' => ['0', 'Configured label', '0'],
]);

it('retains generated Site and Embed labels without a configured default', function(string $class) {
    $link = new $class();
    $link->setFieldLayout($class::getDefaultFieldLayout());
    $site = Craft::$app->sites->getPrimarySite();
    $link->linkValue = $class === \verbb\hyper\links\Site::class
        ? $site->uid
        : ['url' => 'https://example.test/video', 'title' => 'Provider label'];

    expect($link->getLinkText())->toBe($class === \verbb\hyper\links\Site::class ? $site->name : 'Provider label');
})->with([\verbb\hyper\links\Site::class, \verbb\hyper\links\Embed::class]);
