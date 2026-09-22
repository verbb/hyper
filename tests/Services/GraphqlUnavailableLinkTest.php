<?php

use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps available GraphQL links readable alongside unsupported content', function() {
    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]);
    $section = F::entrySection($field);
    $owner = F::plainEntry($section, 'Mixed link types', [$field->handle => [
        F::urlLinkPayload('https://example.test/available', 'Available'),
        ['linkTypeHandle' => 'uninstalled-type', 'uid' => StringHelper::UUID(), 'linkValue' => 'https://example.test/unavailable', 'linkText' => 'Unavailable', 'urlSuffix' => '?retained=1', 'fields' => ['unavailableCustomField' => 'Retained opaque value']],
    ]]);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $original = Craft::$app->gql;
    $original->flushCaches();
    $gql = new \craft\services\Gql();
    Craft::$app->set('gql', $gql);
    try {
        $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Unavailable links', 'scope' => ['sections.' . $section->uid . ':read']]);
        $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . '(empty: null) { __typename text linkText url fields } } } }', debugMode: true);
        expect($result['errors'] ?? [])->toBe([], json_encode($result));
        $selection = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $type->handle . '_Entry { visible: ' . $field->handle . ' { linkText isEmpty } missing: ' . $field->handle . '(empty: true) { linkText isEmpty } } } }', debugMode: true);
        expect($selection['errors'] ?? [])->toBe([], json_encode($selection));
        expect($selection['data']['entries'][0]['visible'])->toBe([['linkText' => 'Available', 'isEmpty' => false]]);
        expect($selection['data']['entries'][0]['missing'])->toBe([['linkText' => 'Unavailable', 'isEmpty' => true]]);
        expect($result['data']['entries'][0][$field->handle])->toBe([
            ['__typename' => $field->handle . '_Url_LinkType', 'text' => 'Available', 'linkText' => 'Available', 'url' => 'https://example.test/available', 'fields' => '[]'],
            ['__typename' => 'HyperMissingLink', 'text' => null, 'linkText' => 'Unavailable', 'url' => null, 'fields' => '[]'],
        ]);
    } finally {
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
});
