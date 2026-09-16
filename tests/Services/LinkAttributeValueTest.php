<?php

use verbb\hyper\links\Url;

it('retains zero in authored and template-supplied HTML attributes', function() {
    $link = new Url([
        'linkValue' => 'https://example.test',
        'linkTitle' => '0',
        'ariaLabel' => '0',
        'customAttributes' => [
            ['attribute' => 'tabindex', 'value' => '0'],
            ['attribute' => 'data-count', 'value' => 0],
        ],
    ]);

    $attributes = $link->getLinkAttributes(['data-position' => 0, 'hidden' => false]);
    expect($attributes['title'] ?? null)->toBe('0');
    expect($attributes['aria']['label'] ?? null)->toBe('0');
    expect($attributes['tabindex'] ?? null)->toBe('0');
    expect($attributes['data']['count'] ?? null)->toBe(0);
    expect($attributes['data']['position'] ?? null)->toBe(0);
    expect((string)$link->getLink())->toContain('tabindex="0"')->toContain('data-count="0"')->toContain('aria-label="0"')->toContain('title="0"');
    expect((string)$link->getLink(['hidden' => false]))->not->toContain(' hidden');
});
