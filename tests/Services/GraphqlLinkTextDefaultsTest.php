<?php

use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Site;
use verbb\hyper\links\Url;

it('resolves GraphQL Link Text defaults separately from editor-entered text', function(string $kind, ?string $authored, ?string $default) {
    $class = match ($kind) {
        'entry' => EntryLink::class,
        'site' => Site::class,
        'embed' => Embed::class,
        default => Url::class,
    };
    $prototype = new $class();
    $layout = $class::getDefaultFieldLayout();
    $layout->getField('linkText')->defaultValue = $default;
    $prototype->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($prototype)]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Destination caption');
    $value = match ($kind) {
        'entry' => [$target->id],
        'site' => Craft::$app->sites->getPrimarySite()->uid,
        'embed' => ['url' => 'https://example.test/video', 'title' => 'Provider caption'],
        default => 'https://example.test/link',
    };
    $expected = $authored ?? $default ?? match ($kind) {
        'entry' => (string)$target,
        'site' => Craft::$app->sites->getPrimarySite()->name,
        'embed' => 'Provider caption',
        default => null,
    };
    $owner = F::plainEntry($section, 'GraphQL defaults', [$field->handle => [[
        'handle' => $prototype->handle, 'linkValue' => $value, 'linkText' => $authored,
    ]]]);
    expect($owner->getFieldValue($field->handle)->first()->getLinkText())->toBe($expected);
    $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Link Text defaults', 'scope' => ['sections.' . $section->uid . ':read']]);
    $original = Craft::$app->gql;
    $original->flushCaches();
    $gql = new \craft\services\Gql();
    Craft::$app->set('gql', $gql);
    try {
        $entryType = $section->getEntryTypes()[0]->handle . '_Entry';
        $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $entryType . ' { ' . $field->handle . ' { linkText customLinkText } } } }', debugMode: true);
        expect($result['errors'] ?? [])->toBe([], json_encode($result));
        expect($result['data']['entries'][0][$field->handle][0])->toBe(['linkText' => $expected, 'customLinkText' => $authored]);
    } finally {
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
})->with([
    'layout default' => ['url', null, 'Configured caption'],
    'zero layout default' => ['url', null, '0'],
    'element title' => ['entry', null, null],
    'site name' => ['site', null, null],
    'provider title' => ['embed', null, null],
    'authored zero' => ['url', '0', 'Configured caption'],
]);
