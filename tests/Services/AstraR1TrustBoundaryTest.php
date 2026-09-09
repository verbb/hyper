<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\helpers\UrlSafety;
use verbb\hyper\Hyper;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Url;

it('escapes author link text in getLink but preserves template Markup', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'handle' => 'url',
        'linkValue' => 'https://example.test',
        'linkText' => '<em>synthetic</em>',
    ]);

    $html = (string)$link->getLink();

    expect($html)->toContain('&lt;em&gt;synthetic&lt;/em&gt;')
        ->and($html)->not->toContain('<em>synthetic</em>');

    $trusted = $link->getLink(['text' => craft\helpers\Template::raw('<em>ok</em>')]);

    expect((string)$trusted)->toContain('<em>ok</em>');
});

it('drops unsafe custom attribute names from rendered links', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->linkValue = 'https://example.test';
    $link->linkText = 'Safe';
    $link->customAttributes = [
        ['attribute' => 'onclick', 'value' => 'alert(1)'],
        ['attribute' => 'data-x', 'value' => 'ok'],
        ['attribute' => 'not valid!', 'value' => 'nope'],
    ];

    $attrs = $link->getCustomAttributes();

    expect($attrs)->toHaveKey('data-x')
        ->and($attrs['data-x'])->toBe('ok')
        ->and($attrs)->not->toHaveKey('onclick')
        ->and($attrs)->not->toHaveKey('not valid!');
});

it('rejects javascript urls on validation and render', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;
    $link->setAttributes(['linkValue' => 'javascript:alert(1)'], false);

    expect($link->validate())->toBeFalse();
    expect($link->getUrl())->toBeNull();
});

it('allows configured extra uri schemes but never blocked schemes', function() {
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

        $blocked = Hyper::$plugin->getLinks()->createLink(Url::class);
        $blocked->field = $field;
        $blocked->setAttributes(['linkValue' => 'javascript:alert(1)'], false);
        $settings->allowedUriSchemes = ['javascript'];

        expect($blocked->validate())->toBeFalse();
        expect(UrlSafety::isAllowedUrl('javascript:alert(1)', ['javascript']))->toBeFalse();
    } finally {
        $settings->allowedUriSchemes = $previous;
    }
});

it('matches embed domains exactly or as parent domains only', function() {
    $settings = Hyper::$plugin->getSettings();

    expect($settings->doesUrlMatchDomain('https://media.example.test/x', ['media.example.test']))->toBeTrue();
    expect($settings->doesUrlMatchDomain('https://cdn.media.example.test/x', ['media.example.test']))->toBeTrue();
    expect($settings->doesUrlMatchDomain('https://media.example.test.unrelated.test/x', ['media.example.test']))->toBeFalse();
    expect($settings->doesUrlMatchDomain('https://example.test/x', ['media.example.test']))->toBeFalse();
});

it('stores string embed urls without fetching during setAttributes', function() {
    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->setAttributes(['linkValue' => 'https://www.youtube.com/watch?v=abc'], false);

    expect($embed->linkValue)->toBe(['url' => 'https://www.youtube.com/watch?v=abc']);
});

it('isolates embed preview html inside a sandboxed data iframe', function() {
    $preview = Embed::getPreviewHtml('<iframe src="https://example.test"></iframe><script>alert(1)</script>');

    expect($preview)->toContain('sandbox=')
        ->and($preview)->toContain('data:text/html')
        ->and($preview)->not->toContain('<script>alert(1)</script>');
});
