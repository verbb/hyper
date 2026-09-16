<?php

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkTypeConfig;

it('updates shared fields after a config rename while preserving detached settings and authored content', function() {
    $configs = Hyper::$plugin->getLinkTypeConfigs();
    $config = new LinkTypeConfig(['name' => 'Shared contract', 'handle' => F::handle('shared'), 'linkTypes' => [F::linkTypeConfig(Url::class)]]);
    $config->linkTypes[0]['placeholder'] = 'Original hint';
    expect($configs->saveConfig($config))->toBeTrue();
    $fixtures = [];
    try {
        foreach (['first', 'second', 'custom'] as $name) {
            $field = new HyperField(['name' => $name, 'handle' => F::handle('hyperTestShared'), 'linkTypeConfig' => $config->uid]);
            if ($name === 'custom') {
                $field->enableCustomLinkTypes();
            }
            expect(Craft::$app->fields->saveField($field))->toBeTrue();
            $owner = F::plainEntry(F::entrySection($field), $name, [$field->handle => [['handle' => 'url', 'linkValue' => 'https://example.test/' . $name, 'linkText' => 'Authored ' . $name]]]);
            $fixtures[$name] = [$field, $owner];
        }
        $config->handle = F::handle('renamed');
        $config->linkTypes[0]['placeholder'] = 'Updated hint';
        expect($configs->saveConfig($config))->toBeTrue();

        // Reload definitions as the next request does, rather than testing stale in-memory prototypes.
        Craft::$app->fields->refreshFields();
        Craft::$app->entries->refreshEntryTypes();
        foreach ($fixtures as $name => [$originalField, $originalOwner]) {
            $field = Craft::$app->fields->getFieldById($originalField->id);
            expect($field->linkTypeConfig)->toBe($name === 'custom' ? 'custom' : $config->uid);
            expect($field->getLinkTypes()[0]->placeholder)->toBe($name === 'custom' ? 'Original hint' : 'Updated hint');
            $owner = Entry::find()->id($originalOwner->id)->one();
            $link = $owner->getFieldValue($field->handle)->getLinks()[0];
            expect($link->getUrl())->toBe('https://example.test/' . $name);
            expect($link->getLinkText())->toBe('Authored ' . $name);
            expect($link->placeholder)->toBe($name === 'custom' ? 'Original hint' : 'Updated hint');
            expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
            $saved = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle);
            expect([$saved->getUrl(), $saved->getLinkText()])->toBe(['https://example.test/' . $name, 'Authored ' . $name]);
        }
    } finally {
        foreach ($fixtures as [$field]) {
            Craft::$app->fields->deleteField($field);
        }
        $configs->deleteConfig($config->uid);
    }
});
