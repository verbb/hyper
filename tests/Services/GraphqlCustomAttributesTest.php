<?php

use craft\gql\TypeLoader;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\gql\types\ArrayType;
use verbb\hyper\links\Url;
use verbb\vizy\gql\types\ArrayType as VizyArrayType;

it('returns custom attributes as structured data alongside Vizy, with and without lazy GraphQL types', function(bool $lazy) {
    // The test app installs Vizy, whose own `ArrayType` scalar used to replace Hyper's.
    expect(Craft::$app->plugins->isPluginEnabled('vizy'))->toBeTrue();

    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]);
    $section = F::entrySection($field);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $owner = F::plainEntry($section, 'Custom attributes owner', [$field->handle => [
        [
            'handle' => Url::typeKey(),
            'linkValue' => 'https://example.test/tracked',
            'linkText' => 'Tracked',
            'customAttributes' => [['attribute' => 'data-track', 'value' => 'cta']],
        ],
        [
            'handle' => Url::typeKey(),
            'linkValue' => 'https://example.test/plain',
            'linkText' => 'Plain',
        ],
    ]]);
    $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Custom attributes', 'scope' => [
        'sections.' . $section->uid . ':read',
    ]]);
    $general = Craft::$app->getConfig()->getGeneral();
    $originalLazy = $general->lazyGqlTypes;
    $original = Craft::$app->gql;
    $original->flushCaches();
    $gql = new \craft\services\Gql();
    Craft::$app->set('gql', $gql);
    $general->lazyGqlTypes = $lazy;

    try {
        $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . ' { url customAttributes } } } }', debugMode: true);

        expect($result['errors'] ?? [])->toBe([], json_encode($result));
        $links = $result['data']['entries'][0][$field->handle];
        expect($links[0]['url'])->toBe('https://example.test/tracked');
        expect($links[0]['customAttributes'])->toBe(['data-track' => 'cta']);
        expect($links[1]['customAttributes'])->toBe([]);
    } finally {
        $general->lazyGqlTypes = $originalLazy;
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
})->with([
    'prebuilt types' => [false],
    'lazy types' => [true],
]);

it('keeps its JSON scalar separate from Vizy’s, whichever registers first', function(bool $hyperFirst) {
    Craft::$app->gql->flushCaches();

    try {
        if ($hyperFirst) {
            $hyper = ArrayType::getType();
            $vizy = VizyArrayType::getType();
        } else {
            $vizy = VizyArrayType::getType();
            $hyper = ArrayType::getType();
        }

        expect($hyper)->toBeInstanceOf(ArrayType::class);
        expect($vizy)->toBeInstanceOf(VizyArrayType::class);
        // Lazy schemas look each type up by its own name, so that name must be the one it's registered under.
        expect($hyper->name)->toBe(ArrayType::getName());
        expect(TypeLoader::loadType($hyper->name))->toBe($hyper);
        expect(TypeLoader::loadType($vizy->name))->toBe($vizy);
        expect($hyper->serialize(['data-track' => 'cta']))->toBe(['data-track' => 'cta']);
        expect($vizy->serialize(['data-track' => 'cta']))->toBe('{"data-track":"cta"}');
    } finally {
        Craft::$app->gql->flushCaches();
    }
})->with([
    'Hyper first' => [true],
    'Vizy first' => [false],
]);
