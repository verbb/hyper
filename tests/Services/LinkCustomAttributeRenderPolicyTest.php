<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('applies the URL policy to form destinations and keeps tag() content keys out of saved custom attributes', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $section = F::entrySection($field);

    // Rows saved before updating are filtered when they render, without changing what's stored.
    $rows = [
        ['attribute' => 'formaction', 'value' => 'javascript:void(0)'],
        ['attribute' => 'FormAction', 'value' => 'JavaScript:void(0)'],
        ['attribute' => 'action', 'value' => 'javascript:void(0)'],
        ['attribute' => 'html', 'value' => '<img src="x">'],
        ['attribute' => 'TEXT', 'value' => 'Author label'],
        ['attribute' => 'form', 'value' => 'search'],
        ['attribute' => 'data-track', 'value' => 'cta'],
    ];
    $owner = F::plainEntry($section, 'Custom attribute render policy', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/policy', 'linkText' => 'Read more', 'customAttributes' => $rows,
    ]]]);
    $link = Craft::$app->getEntries()->getEntryById($owner->id)->getFieldValue($field->handle)->first();
    $view = Craft::$app->getView();

    expect($link->getCustomAttributes())->toBe(['form' => 'search', 'data-track' => 'cta']);
    expect($link->customAttributes)->toEqual($rows);

    $button = $view->renderString('<button{{ attr(link.getLinkAttributes()) }}>{{ link.text }}</button>', ['link' => $link]);
    expect($button)->not->toContain('javascript:')->not->toContain('JavaScript:')->toContain('form="search"');

    // Craft's tag() keeps the template's escaped label, as it does for Craft's Link field.
    expect($view->renderString('{{ tag("a", link.getLinkAttributes({ text: link.text })) }}', ['link' => $link]))
        ->toBe('<a href="https://example.test/policy" form="search" data-track="cta">Read more</a>');
    expect($view->renderString('{{ tag("a", link.getLinkAttributes()) }}', ['link' => $link]))
        ->toBe('<a href="https://example.test/policy" form="search" data-track="cta"></a>');
    expect((string)$link->getLink())->toBe('<a href="https://example.test/policy" form="search" data-track="cta">Read more</a>');

    // Allowed destinations still render, and template-supplied attributes stay trusted.
    $link->customAttributes = [
        ['attribute' => 'formaction', 'value' => '/search'],
        ['attribute' => 'action', 'value' => 'https://example.test/submit'],
    ];
    expect($link->getCustomAttributes())->toBe(['formaction' => '/search', 'action' => 'https://example.test/submit']);
    expect($link->getLinkAttributes(['formaction' => 'slack://trusted'])['formaction'])->toBe('slack://trusted');
    expect($view->renderString('{{ tag("a", link.getLinkAttributes({ html: "<b>Bold</b>" })) }}', ['link' => $link]))
        ->toContain('><b>Bold</b></a>');
});
