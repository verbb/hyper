<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\fieldlayoutelements\BaseField;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkInstance;

it('resolves target from the field default new window setting', function() {
    $field = HyperFixtureFactory::hyperField();
    $field->newWindow = true;
    $field->defaultNewWindow = true;
    Craft::$app->fields->saveField($field);
    $field = Craft::$app->fields->getFieldByHandle($field->handle);

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'linkTypeHandle' => 'default-verbb-hyper-links-url',
        'linkValue' => 'https://example.test/default-window',
    ]);

    expect($link)->not->toBeNull();
    expect($link->newWindow)->toBeNull();
    expect($link->getTarget())->toBe('_blank');
    expect($link->getNewWindow())->toBeTrue();
});

it('persists an explicit new window override on an otherwise empty link', function() {
    $field = HyperFixtureFactory::hyperField();
    $field->newWindow = true;
    $field->defaultNewWindow = true;
    Craft::$app->fields->saveField($field);
    $field = Craft::$app->fields->getFieldByHandle($field->handle);

    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks($section, [[
        'linkTypeHandle' => 'default-verbb-hyper-links-url',
        'newWindow' => false,
    ]]);

    $collection = $entry->getFieldValue($field->handle);

    expect($collection)->toBeInstanceOf(LinkCollection::class);
    expect($collection->isEmpty())->toBeFalse();

    $serialized = $field->serializeValue($collection, $entry);
    expect($serialized[0]['newWindow'] ?? null)->toBeFalse();

    $reloaded = Craft::$app->getElements()->getElementById($entry->id, Entry::class, $entry->siteId);
    $reloadedCollection = $reloaded->getFieldValue($field->handle);
    $reloadedLink = $reloadedCollection->first();

    expect($reloadedLink)->not->toBeNull();
    expect($reloadedLink->newWindow)->toBeFalse();
    expect($reloadedLink->getTarget())->toBeNull();
});

it('treats an explicit new window value as meaningful for empty checks', function() {
    $instance = new LinkInstance();
    $instance->linkTypeHandle = 'default-verbb-hyper-links-url';
    $instance->newWindow = false;

    expect(Url::isInstanceEmpty($instance))->toBeFalse();
});

it('does not render link value input when removed from the link type layout', function() {
    $field = HyperFixtureFactory::hyperField();
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->field = $field;

    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements(array_values(array_filter(
        $tab->getElements(),
        static fn(BaseField $element): bool => !($element instanceof LinkField),
    )));

    if (!$tab->getElements()) {
        $tab->setElements([
            Craft::createObject([
                'class' => LinkTextField::class,
                'width' => 100,
            ]),
        ]);
    }

    $link->setFieldLayout($layout);

    $html = $link->getInputHtml(
        Craft::createObject(['class' => LinkField::class]),
        $field,
    );

    expect($html)->toBe('');
    expect($link->getFieldLayout()?->isFieldIncluded('linkValue'))->toBeFalse();
});

it('preserves embed urls when only a url string is provided on save', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Embed::class],
    ]);
    $section = HyperFixtureFactory::entrySection($field);
    $linkType = $field->getLinkTypes()[0];

    $embed = Hyper::$plugin->getLinks()->createLink(Embed::class);
    $embed->field = $field;
    $embed->handle = $linkType->handle;
    $embed->setAttributes([
        'linkValue' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ], false);

    expect($embed->getLinkUrl())->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    expect($embed->validate())->toBeTrue();

    $entry = HyperFixtureFactory::entryWithLinks($section, [$embed->getSerializedValues()]);
    $collection = $entry->getFieldValue($field->handle);

    expect($collection->isEmpty())->toBeFalse();
    expect($collection->getLinkUrl())->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    expect($collection->getIframeSrc())->toContain('youtube.com/embed');
    expect($collection->getEmbedImage())->toContain('ytimg.com');
});
