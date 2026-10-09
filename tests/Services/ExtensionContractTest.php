<?php

use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use verbb\hyper\services\Links;
use yii\base\Event;

class RegisteredDocumentationUrl extends Url
{
    public static function typeKey(): string { return 'exampleDocumentation'; }
}

it('saves and renders an event-registered link type through Twig and GraphQL', function() {
    $register = static function(RegisterComponentTypesEvent $event): void {
        $event->types[] = RegisteredDocumentationUrl::class;
    };
    Event::on(Links::class, Links::EVENT_REGISTER_LINK_TYPES, $register);
    $originalGql = Craft::$app->gql;
    try {
        expect(Hyper::$plugin->links->getAllLinkTypes())->toContain(RegisteredDocumentationUrl::class);
        $field = F::hyperField(['linkTypes' => [RegisteredDocumentationUrl::class]]);
        $section = F::entrySection($field);
        $owner = F::plainEntry($section, 'Documentation owner', [$field->handle => [[
            'linkTypeHandle' => 'exampleDocumentation', 'linkValue' => 'https://example.test/docs', 'linkText' => 'Documentation',
        ]]]);
        $link = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
        expect($link)->toBeInstanceOf(RegisteredDocumentationUrl::class);
        expect(Craft::$app->view->renderString('{{ link.getLink() }}', ['link' => $link]))
            ->toBe('<a href="https://example.test/docs">Documentation</a>');
        $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
        $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Registered link', 'scope' => ['sections.' . $section->uid . ':read']]);
        $originalGql->flushCaches();
        $gql = new craft\services\Gql();
        Craft::$app->set('gql', $gql);
        $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . ' { __typename url text } } } }', debugMode: true);
        expect($result['errors'] ?? [])->toBe([]);
        expect($result['data']['entries'][0][$field->handle])->toBe([[
            '__typename' => $field->handle . '_ExampleDocumentation_LinkType', 'url' => 'https://example.test/docs', 'text' => 'Documentation',
        ]]);
    } finally {
        Craft::$app->gql->flushCaches();
        Craft::$app->set('gql', $originalGql);
        $originalGql->flushCaches();
        Event::off(Links::class, Links::EVENT_REGISTER_LINK_TYPES, $register);
    }
});

it('uses the documented migration event for both source mapping and converted content', function() {
    $map = static function(verbb\hyper\events\ModifyMigrationLinkEvent $event): void {
        if ($event->oldClass === 'example-docs') {
            $event->newClass = Url::class;
        }
    };
    $classes = [MigrateTypedLinkField::class, MigrateTypedLinkContent::class];
    foreach ($classes as $class) {
        Event::on($class, $class::EVENT_MODIFY_LINK_TYPE, $map);
    }
    try {
        expect((new MigrateTypedLinkField())->getLinkType('example-docs'))->toBe(Url::class);
        $field = F::hyperField(['linkTypes' => [Url::class]]);
        $converted = (new MigrateTypedLinkContent())->convertModel($field, [
            'type' => 'example-docs', 'value' => 'https://example.test/docs', 'customText' => 'Docs',
        ]);
        $link = $field->normalizeValue($converted)->first();
        expect($link)->toBeInstanceOf(Url::class);
        expect($link->getUrl())->toBe('https://example.test/docs');
        expect($link->getText())->toBe('Docs');
    } finally {
        foreach ($classes as $class) {
            Event::off($class, $class::EVENT_MODIFY_LINK_TYPE, $map);
        }
    }
});
