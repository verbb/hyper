<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps saved custom attribute rows raw and returns validated attributes from the getter', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $section = F::entrySection($field);
    $rows = [
        ['attribute' => 'data-track', 'value' => 'cta'],
        ['attribute' => 'onclick', 'value' => 'track()'],
        ['attribute' => 'href', 'value' => 'javascript:void(0)'],
    ];
    $owner = F::plainEntry($section, 'Custom attribute access', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/access', 'linkText' => 'Access', 'customAttributes' => $rows,
    ]]]);
    $link = Craft::$app->getEntries()->getEntryById($owner->id)->getFieldValue($field->handle)->first();
    $view = Craft::$app->getView();

    // The documented property is the saved rows, so editors keep what they entered.
    expect(json_decode($view->renderString('{{ link.customAttributes|json_encode }}', ['link' => $link]), true))->toEqual($rows);

    // The documented getter is the validated name/value map that rendering and GraphQL use.
    expect(json_decode($view->renderString('{{ link.getCustomAttributes()|json_encode }}', ['link' => $link]), true))->toBe(['data-track' => 'cta']);
    expect($view->renderString('<a{{ attr(link.getCustomAttributes()) }}>', ['link' => $link]))->toBe('<a data-track="cta">');
    expect($link->customAttributes)->toEqual($rows);
});
