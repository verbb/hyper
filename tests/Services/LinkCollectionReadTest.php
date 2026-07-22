<?php

declare(strict_types=1);

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkCollectionInterface;

it('persists and reloads URL link values on entries', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/contact', 'Contact us')],
    );

    $reloaded = Craft::$app->getElements()->getElementById($entry->id, Entry::class, $entry->siteId);
    $links = $reloaded->getFieldValue($field->handle);

    expect($links)->toBeInstanceOf(LinkCollection::class);
    expect($links)->toBeInstanceOf(LinkCollectionInterface::class);
    expect($links->getUrl())->toBe('https://example.test/contact');
    expect($links->getText())->toBe('Contact us');
});

it('writes hyper_links rows for entry-backed links on save', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $targets = HyperFixtureFactory::entries(2, $section);
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($targets[0], 'First target')],
        'Owner entry',
    );

    $relationRows = (new Query())
        ->from('{{%hyper_links}}')
        ->where([
            'ownerId' => $owner->id,
            'ownerSiteId' => $owner->siteId,
            'fieldId' => $field->id,
            'targetId' => $targets[0]->id,
        ])
        ->count();

    expect((int)$relationRows)->toBe(1);
});

it('keeps first() in sync after setLinks()', function() {
    $field = HyperFixtureFactory::hyperField();
    $first = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/first', 'First'),
    );
    $second = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/second', 'Second'),
    );

    $collection = new LinkCollection($field, [$first]);
    expect($collection->first()?->getCustomLinkText())->toBe('First');
    expect($collection->getUrl())->toBe('https://example.test/first');

    $collection->setLinks([$second]);
    expect($collection->first()?->getCustomLinkText())->toBe('Second');
    expect($collection->getUrl())->toBe('https://example.test/second');
});

it('returns a new instance from withLinks() without mutating the original', function() {
    $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
    $first = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/first', 'First'),
    );
    $second = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/second', 'Second'),
    );

    $original = new LinkCollection($field, [$first]);
    $updated = $original->withLinks([$first, $second]);

    expect($original->getLinks())->toHaveCount(1);
    expect($updated->getLinks())->toHaveCount(2);
    expect($updated)->not->toBe($original);
    expect($updated->first()?->getCustomLinkText())->toBe('First');
});

it('treats multi-link collections with only empty slots as empty', function() {
    $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
    $emptyLink = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'type' => Url::class,
        'handle' => 'default-' . StringHelper::toKebabCase(Url::class),
        'linkValue' => '',
    ]);

    $collection = new LinkCollection($field, [$emptyLink, $emptyLink]);

    expect($collection->count())->toBe(2);
    expect($collection->isEmpty())->toBeTrue();
    expect($field->isValueEmpty($collection, new Entry()))->toBeTrue();
});

it('delegates property writes to the first link', function() {
    $field = HyperFixtureFactory::hyperField();
    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/start', 'Start'),
    );
    $collection = new LinkCollection($field, [$link]);

    $collection->linkText = 'Updated';

    expect($collection->getCustomLinkText())->toBe('Updated');
    expect($collection->first()?->getCustomLinkText())->toBe('Updated');
});

it('does not clone the field definition when the collection is cloned', function() {
    $field = HyperFixtureFactory::hyperField();
    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/clone', 'Clone'),
    );
    $collection = new LinkCollection($field, [$link]);
    $clone = clone $collection;

    expect($clone)->not->toBe($collection);
    expect($clone->first())->not->toBe($collection->first());
    expect($clone->getUrl())->toBe($collection->getUrl());
});

it('keeps first() in sync after array access mutations', function() {
    $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
    $first = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/first', 'First'),
    );
    $second = Hyper::$plugin->getLinks()->createLinkFromSerialized(
        $field,
        HyperFixtureFactory::urlLinkPayload('https://example.test/second', 'Second'),
    );

    $collection = new LinkCollection($field, [$first]);
    $collection[] = $second;

    expect($collection->getLinks())->toHaveCount(2);
    expect($collection->first()?->getCustomLinkText())->toBe('First');

    unset($collection[0]);

    expect($collection->getLinks())->toHaveCount(1);
    expect($collection->first()?->getCustomLinkText())->toBe('Second');
});
