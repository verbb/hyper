<?php

use craft\fieldlayoutelements\CustomField;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps native GraphQL fields authoritative while retaining colliding custom values', function(string $fieldClass, mixed $value) {
    $custom = new $fieldClass(['name' => 'Custom description', 'handle' => F::handle('hyperDescription')]);
    expect(Craft::$app->fields->saveField($custom))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(),
        new CustomField($custom, ['uid' => StringHelper::UUID(), 'handle' => 'text']),
        new CustomField($custom, ['uid' => StringHelper::UUID(), 'handle' => 'description']),
    ]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $section = F::entrySection($field);
    $owner = F::plainEntry($section, 'Native GraphQL fields', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/native', 'linkText' => 'Native caption',
        'fields' => ['text' => $value, 'description' => $value],
    ]]]);
    expect($owner->getFieldValue($field->handle)->first()->getText())->toBe('Native caption');
    $entryType = $section->getEntryTypes()[0]->handle . '_Entry';
    $linkType = $field->handle . '_Url_LinkType';
    $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Native field collision', 'scope' => ['sections.' . $section->uid . ':read']]);
    $original = Craft::$app->gql;
    $original->flushCaches();
    $gql = new \craft\services\Gql();
    Craft::$app->set('gql', $gql);
    try {
        foreach (['text fields formatted: text @markdown', 'text fields formatted: text @markdown ... on ' . $linkType . ' { text description }'] as $selection) {
            $query = '{ entries(id: ' . $owner->id . ') { ... on ' . $entryType . ' { ' . $field->handle . ' { ' . $selection . ' } } } }';
            $result = $gql->executeQuery($schema, $query, debugMode: true);
            expect($result['errors'] ?? [])->toBe([], json_encode($result));
            $link = $result['data']['entries'][0][$field->handle][0];
            expect($link['text'])->toBe('Native caption');
            expect(trim($link['formatted']))->toBe('<p>Native caption</p>');
            expect(json_decode($link['fields'], true))->toBe(['text' => $value, 'description' => $value]);
            if (str_contains($selection, 'description')) {
                expect($link['description'])->toBe($value);
            }
        }
    } finally {
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
})->with([
    'different GraphQL type' => [Number::class, 42],
    'same GraphQL type' => [PlainText::class, 'Custom description'],
]);
