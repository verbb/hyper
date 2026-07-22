<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\helpers\EmbedImagesExtractor;
use verbb\hyper\Hyper;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Url;

it('parses iframe src from embed html', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Embed::class],
    ]);
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->field = $field;
    $embed->handle = $field->getLinkTypes()[0]->handle;
    $embed->linkValue = [
        'url' => 'https://www.youtube.com/watch?v=abc',
        'code' => '<iframe src="https://www.youtube.com/embed/abc" title="Test"></iframe>',
        'providerName' => 'YouTube',
        'image' => 'https://i.ytimg.com/vi/abc/hqdefault.jpg',
    ];

    expect($embed->getIframeSrc())->toBe('https://www.youtube.com/embed/abc');
    expect((string)$embed->getHtml())->toContain('youtube.com/embed/abc');
    expect($embed->getEmbedProviderName())->toBe('YouTube');
    expect($embed->getEmbedImage())->toBe('https://i.ytimg.com/vi/abc/hqdefault.jpg');
});

it('returns null iframe src for non-embed links', function() {
    $url = Hyper::$plugin->getLinks()->createLink(Url::class);
    $url->linkValue = 'https://example.com';

    expect($url->getIframeSrc())->toBeNull();
    expect($url->getHtml())->toBeNull();
});

it('enforces per link-type allowed domains', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->allowedDomains = ['youtube.com'];

    expect($embed->isEmbedUrlAllowed('https://www.youtube.com/watch?v=1'))->toBeTrue();
    expect($embed->isEmbedUrlAllowed('https://vimeo.com/123'))->toBeFalse();

    $embed->linkValue = [
        'url' => 'https://vimeo.com/123',
        'providerName' => 'Vimeo',
        'code' => '<iframe src="https://player.vimeo.com/video/123"></iframe>',
    ];

    expect($embed->validate())->toBeFalse();
    expect($embed->getErrors('linkValue'))->not->toBeEmpty();
});

it('falls back to plugin embedAllowedDomains when link-type list is empty', function() {
    $settings = Hyper::$plugin->getSettings();
    $previous = $settings->embedAllowedDomains;
    $settings->embedAllowedDomains = ['youtube.com'];

    try {
        $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
        $embed->allowedDomains = [];

        expect($embed->getEffectiveAllowedDomains())->toBe(['youtube.com']);
        expect($embed->isEmbedUrlAllowed('https://www.youtube.com/watch?v=1'))->toBeTrue();
        expect($embed->isEmbedUrlAllowed('https://example.com'))->toBeFalse();
    } finally {
        $settings->embedAllowedDomains = $previous;
    }
});

it('builds youtube maxresdefault candidates from lower-res thumbs', function() {
    $hq = 'https://i.ytimg.com/vi/jfKfPfyJRdk/hqdefault.jpg';

    expect(EmbedImagesExtractor::youtubeMaxResCandidate($hq))
        ->toBe('https://i.ytimg.com/vi/jfKfPfyJRdk/maxresdefault.jpg');
    expect(EmbedImagesExtractor::youtubeMaxResCandidate('https://example.com/thumb.jpg'))->toBeNull();
});

it('serializes layout fields bag for graphql-style access', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Url::class],
    ]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->handle = $field->getLinkTypes()[0]->handle;
    $link->linkValue = 'https://example.com';

    expect($link->getSerializedLayoutFields())->toBeArray();
});
