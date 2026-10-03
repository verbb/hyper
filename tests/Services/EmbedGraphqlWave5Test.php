<?php

declare(strict_types=1);

use craft\helpers\Json;
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
    $embed->linkValue = Embed::prepareEmbedData([
        'url' => 'https://www.youtube.com/watch?v=abc',
        'code' => '<iframe src="https://www.youtube.com/embed/abc" title="Test"></iframe>',
        'providerName' => 'YouTube',
        'image' => 'https://i.ytimg.com/vi/abc/hqdefault.jpg',
    ]);

    expect($embed->getIframeSrc())->toBe('https://www.youtube.com/embed/abc');
    expect((string)$embed->getHtml())->toContain('youtube.com/embed/abc');
    expect($embed->getEmbedProviderName())->toBe('YouTube');
    expect($embed->getEmbedImage())->toBe('https://i.ytimg.com/vi/abc/hqdefault.jpg');
});

it('rejects forged embed html without server provenance', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->setAttributes(['linkValue' => Json::encode([
        'url' => 'https://video.example.test/watch',
        'code' => '<img src=x onerror="alert(document.domain)">',
        'providerName' => 'Forged provider',
    ])], false);

    expect($embed->getHtml())->toBeNull();
    expect($embed->getIframeSrc())->toBeNull();
    expect($embed->getSerializedValues()['linkValue'])->not->toHaveKey('code');
});

it('accepts sealed metadata after a JSON key-order round trip', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $data = Embed::prepareEmbedData([
        'url' => 'https://video.example.test/watch',
        'code' => '<iframe src="https://video.example.test/embed"></iframe>',
        'providerName' => 'Example Video',
    ]);
    $embed->setAttributes(['linkValue' => Json::encode([
        'providerName' => $data['providerName'],
        '_hyperEmbedSignature' => $data['_hyperEmbedSignature'],
        'code' => $data['code'],
        'url' => $data['url'],
    ])], false);

    expect((string)$embed->getHtml())->toContain('https://video.example.test/embed');
});

it('sanitizes and seals server-provided embed html', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->linkValue = Embed::prepareEmbedData([
        'url' => 'https://video.example.test/watch',
        'code' => '<script>alert(1)</script><iframe src="https://video.example.test/embed" title="Video" onload="alert(2)"></iframe>',
        'providerName' => 'Example Video',
    ]);

    $html = (string)$embed->getHtml();

    expect($html)->toContain('<iframe');
    expect($html)->toContain('https://video.example.test/embed');
    expect($html)->toContain('title="Video"');
    expect($html)->not->toContain('<script');
    expect($html)->not->toContain('onload');
    expect($embed->getIframeSrc())->toBe('https://video.example.test/embed');
});

it('rejects signed metadata after any client-side tampering', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->linkValue = Embed::prepareEmbedData([
        'url' => 'https://video.example.test/watch',
        'code' => '<iframe src="https://video.example.test/embed"></iframe>',
        'providerName' => 'Example Video',
    ]);
    $embed->linkValue['code'] = '<img src=x onerror="alert(1)">';

    expect($embed->getHtml())->toBeNull();
    expect($embed->getIframeSrc())->toBeNull();
    expect($embed->getData())->not->toHaveKeys(['code', '_hyperEmbedSignature']);
});

it('rechecks current domain policy before rendering sealed html', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->linkValue = Embed::prepareEmbedData([
        'url' => 'https://video.example.test/watch',
        'code' => '<iframe src="https://video.example.test/embed"></iframe>',
    ]);
    $embed->allowedDomains = ['youtube.com'];

    expect($embed->getHtml())->toBeNull();
    expect($embed->getIframeSrc())->toBeNull();
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
