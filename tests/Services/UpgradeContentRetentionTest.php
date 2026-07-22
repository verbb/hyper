<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

it('retains hyper field content after an element resave', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Url::class, EntryLink::class],
    ]);
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/upgrade', 'Upgrade me')],
        'Upgrade retention owner',
    );

    $entry->title = 'Updated title only';
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $link = $entry->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getLinkUrl())->toBe('https://example.test/upgrade');
    expect($link->getCustomLinkText())->toBe('Upgrade me');
});

it('retains legacy v2 content shape after resave', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::plainEntry(
        $section,
        'Legacy content owner',
        [
            $field->handle => [
                [
                    'type' => Url::class,
                    'handle' => 'default-' . \craft\helpers\StringHelper::toKebabCase(Url::class),
                    'linkValue' => 'https://example.test/legacy-v2',
                    'linkText' => 'Legacy link',
                    'newWindow' => false,
                ],
            ],
        ],
    );

    $entry->title = 'Legacy content owner updated';
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $link = $entry->getFieldValue($field->handle)->getLinks()[0];
    $serialized = $field->serializeValue($entry->getFieldValue($field->handle), $entry);

    expect($link->getLinkUrl())->toBe('https://example.test/legacy-v2');
    expect($link->getCustomLinkText())->toBe('Legacy link');
    expect($serialized[0]['linkTypeHandle'] ?? null)->toBe('default-verbb-hyper-links-url');
    expect($serialized[0]['type'] ?? null)->toBeNull();
    expect($serialized[0]['handle'] ?? null)->toBeNull();
});

it('retains attribute-only hyper content after resave', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $section = HyperFixtureFactory::entrySection($field);
    $entry = HyperFixtureFactory::plainEntry(
        $section,
        'Attribute-only owner',
        [
            $field->handle => [
                [
                    'type' => Url::class,
                    'handle' => 'default-' . \craft\helpers\StringHelper::toKebabCase(Url::class),
                    'linkText' => 'Label without URL',
                    'newWindow' => false,
                ],
            ],
        ],
    );

    $entry->title = 'Attribute-only owner updated';
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $link = $entry->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getCustomLinkText())->toBe('Label without URL');
    expect($link->getLinkUrl())->toBeNull();
    expect($field->isValueEmpty($entry->getFieldValue($field->handle), $entry))->toBeFalse();

    $rawContent = (new \craft\db\Query())
        ->select(['content'])
        ->from(['{{%elements_sites}}'])
        ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])
        ->scalar();

    expect(Json::decodeIfJson($rawContent))->not->toBeEmpty();
});
