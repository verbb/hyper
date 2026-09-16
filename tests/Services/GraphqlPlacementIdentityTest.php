<?php

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps different Hyper fields distinct when layouts give them the same alias', function() {
    $fixtures = [];
    foreach (['First', 'Second'] as $label) {
        $caption = new PlainText(['name' => $label . ' caption', 'handle' => F::handle('hyperCaption')]);
        expect(Craft::$app->fields->saveField($caption))->toBeTrue();
        $url = new Url();
        $layout = Url::getDefaultFieldLayout();
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField($caption)]);
        $url->setFieldLayout($layout);
        $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
        $section = F::entrySection($field);
        $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
        foreach ($type->getFieldLayout()->getCustomFieldElements() as $placement) {
            if ($placement->getField()->uid === $field->uid) {
                $placement->handle = 'sharedApiLinks';
                $placement->setField($field);
            }
        }
        $type->getFieldLayout()->setTabs($type->getFieldLayout()->getTabs());
        expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
        Craft::$app->entries->refreshEntryTypes();
        $owner = F::plainEntry($section, $label, ['sharedApiLinks' => [[
            'handle' => 'url', 'linkValue' => 'https://example.test/' . strtolower($label), 'fields' => [$caption->handle => $label],
        ]]]);
        $fixtures[] = compact('field', 'section', 'type', 'owner', 'caption', 'label');
    }
    $original = Craft::$app->gql;
    $original->flushCaches();
    $gql = new \craft\services\Gql();
    Craft::$app->set('gql', $gql);
    try {
        $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Alias boundary', 'scope' => array_map(fn($f) => 'sections.' . $f['section']->uid . ':read', $fixtures)]);
        $selection = implode(' ', array_map(fn($f) => '... on ' . $f['type']->handle . '_Entry { sharedApiLinks { __typename fields } }', $fixtures));
        $ids = implode(',', array_map(fn($f) => $f['owner']->id, $fixtures));
        $result = $gql->executeQuery($schema, '{ entries(id: [' . $ids . '], orderBy: "id ASC") { ' . $selection . ' } }', debugMode: true);
        expect($result['errors'] ?? [])->toBe([], json_encode($result));
        $links = array_map(fn($entry) => $entry['sharedApiLinks'][0], $result['data']['entries']);
        expect(array_unique(array_column($links, '__typename')))->toHaveCount(2);
        foreach ($fixtures as $i => $fixture) {
            expect($links[$i]['__typename'])->toBe($fixture['field']->handle . '_Url_LinkType');
            expect(json_decode($links[$i]['fields'], true))->toBe([$fixture['caption']->handle => $fixture['label']]);
            $name = $links[$i]['__typename'];
            $query = '{ entries(id: ' . $fixture['owner']->id . ') { ... on ' . $fixture['type']->handle . '_Entry { sharedApiLinks { ... on ' . $name . ' { ' . $fixture['caption']->handle . ' } } } } }';
            $fragment = $gql->executeQuery($schema, $query, debugMode: true);
            expect($fragment['errors'] ?? [])->toBe([], json_encode($fragment));
            expect($fragment['data']['entries'][0]['sharedApiLinks'][0][$fixture['caption']->handle])->toBe($fixture['label']);
        }
    } finally {
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
});
