<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkInstance;

it('modifies Hyper field content via the content service', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/before', 'Before')],
    );

    $result = Hyper::$plugin->getContent()->modify($field, function(LinkCollection $collection) {
        $links = $collection->getLinks();
        $link = $links[0];
        $link->linkText = 'After';

        return $collection->withLinks($links);
    }, new ModifyOptions(elementIds: [$entry->id]));

    expect($result->matched)->toBe(1);
    expect($result->modified)->toBe(1);

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $links = $entry->getFieldValue($field->handle);

    expect($links->getLinks()[0]->linkText)->toBe('After');
});

it('supports dry-run content modification without writing', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/before', 'Before')],
    );

    $result = Hyper::$plugin->getContent()->modify($field, function(LinkCollection $collection) {
        $links = $collection->getLinks();
        $link = $links[0];
        $link->linkText = 'Dry run';

        return $collection->withLinks($links);
    }, new ModifyOptions(dryRun: true, elementIds: [$entry->id]));

    expect($result->matched)->toBe(1);
    expect($result->modified)->toBe(0);
    expect($result->wouldModify)->toBe(1);

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $links = $entry->getFieldValue($field->handle);

    expect($links->getLinks()[0]->linkText)->toBe('Before');
});

it('modifies link instances via modifyLinkInstances', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Url::class],
    ]);
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/path', 'Original')],
    );

    $result = Hyper::$plugin->getContent()->modifyLinkInstances($field, function(LinkInstance $instance): LinkInstance {
        $instance->linkText = 'Updated via instance';

        return $instance;
    }, new ModifyOptions(elementIds: [$entry->id]));

    expect($result->modified)->toBe(1);

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $links = $entry->getFieldValue($field->handle);

    expect($links->getLinks()[0]->linkText)->toBe('Updated via instance');
});

it('finds field layout uids for a Hyper field', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/layout-uid')],
    );

    $store = new verbb\hyper\content\ElementContentStore();
    $layoutUids = $store->findLayoutUids($field);

    expect($layoutUids)->not->toBeEmpty();
});
