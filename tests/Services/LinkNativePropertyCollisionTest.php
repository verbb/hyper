<?php

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps native link properties authoritative in Twig when a layout field handle collides', function() {
    $custom = new PlainText(['name' => 'Custom caption', 'handle' => F::handle('hyperCaption')]);
    expect(Craft::$app->fields->saveField($custom))->toBeTrue();
    $handles = ['text', 'target', 'type', 'element', 'html', 'linkUrl', 'summary'];
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), ...array_map(
        fn(string $handle) => new CustomField($custom, ['uid' => StringHelper::UUID(), 'handle' => $handle]),
        $handles,
    )]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $section = F::entrySection($field);
    $owner = F::plainEntry($section, 'Native link properties', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/native', 'linkText' => 'Native label', 'newWindow' => true,
        'fields' => array_combine($handles, array_map(fn(string $handle) => 'Custom ' . $handle, $handles)),
    ]]]);
    $owner = Craft::$app->getEntries()->getEntryById($owner->id);
    $link = $owner->getFieldValue($field->handle)->first();

    $view = Craft::$app->getView();
    $native = '{{ link.text }}|{{ link.target }}|{{ link.type }}|{{ link.element is null ? "null" }}|{{ link.html }}|{{ link.linkUrl }}|{{ link["text"] }}';
    expect($view->renderString($native, ['link' => $link]))
        ->toBe('Native label|_blank|' . Url::class . '|null||https://example.test/native|Native label');
    expect($link->text)->toBe('Native label');
    expect(isset($link->element))->toBeFalse();

    // Colliding custom values stay readable, and other custom handles resolve as before.
    $custom = '{{ link.getFieldValue("text") }}|{{ link.getFieldValue("target") }}|{{ attribute(link, "field:type") }}|{{ link.summary }}';
    expect($view->renderString($custom, ['link' => $link]))->toBe('Custom text|Custom target|Custom type|Custom summary');

    // Existing layouts with these handles still save without losing their content.
    expect(Craft::$app->fields->saveField(Craft::$app->fields->getFieldById($field->id)))->toBeTrue();
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();
    $reloaded = Craft::$app->getEntries()->getEntryById($owner->id)->getFieldValue($field->handle)->first();
    expect($reloaded->getFieldValue('text'))->toBe('Custom text');
    expect($reloaded->text)->toBe('Native label');
});
