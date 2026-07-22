<?php

declare(strict_types=1);

use craft\base\Field;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;

it('persists link text when linkValue is set to an empty string programmatically', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $section = HyperFixtureFactory::entrySection($field);

    $entry = HyperFixtureFactory::plainEntry(
        $section,
        'Programmatic owner',
        [
            $field->handle => [
                [
                    'type' => Url::class,
                    'handle' => 'default-' . \craft\helpers\StringHelper::toKebabCase(Url::class),
                    'linkValue' => '',
                    'linkText' => 'Link label',
                    'newWindow' => false,
                ],
            ],
        ],
    );

    $collection = $entry->getFieldValue($field->handle);

    expect($collection)->toBeInstanceOf(LinkCollection::class);
    expect($field->isValueEmpty($collection, $entry))->toBeFalse();
    expect($collection->getLinks()[0]->getCustomLinkText())->toBe('Link label');
    expect($collection->getLinks()[0]->isEmpty())->toBeFalse();

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $collection = $entry->getFieldValue($field->handle);

    expect($collection->getLinks()[0]->getCustomLinkText())->toBe('Link label');
    expect($collection->getLinks()[0]->getLinkUrl())->toBeNull();
});

it('persists attribute-only links on a localized site', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Url::class],
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);

    $ownerSiteA = HyperFixtureFactory::plainEntry(
        $section,
        'Owner',
        [
            $field->handle => [
                HyperFixtureFactory::urlLinkPayload('https://example.test/home', 'Home'),
            ],
        ],
        $sites[0],
    );

    $ownerSiteB = HyperFixtureFactory::localizedEntryForSite($ownerSiteA, $sites[1]);
    $ownerSiteB->setFieldValue($field->handle, [
        [
            'type' => Url::class,
            'handle' => 'default-' . \craft\helpers\StringHelper::toKebabCase(Url::class),
            'linkValue' => '',
            'linkText' => 'Accueil',
            'newWindow' => false,
        ],
    ]);

    expect(Craft::$app->getElements()->saveElement($ownerSiteB))->toBeTrue();

    $ownerSiteB = Entry::find()->id($ownerSiteB->id)->siteId($sites[1]->id)->status(null)->one();
    $link = $ownerSiteB->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getCustomLinkText())->toBe('Accueil');
    expect($link->getLinkUrl())->toBeNull();
});

it('normalizes an empty-string linkValue to null on hydration', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $section = HyperFixtureFactory::entrySection($field);

    $collection = $field->normalizeValue([
        [
            'type' => Url::class,
            'handle' => 'default-' . \craft\helpers\StringHelper::toKebabCase(Url::class),
            'linkValue' => '',
            'linkText' => 'Link label',
        ],
    ]);

    expect($collection->getLinks()[0]->linkValue)->toBeNull();
    expect($collection->isEmpty())->toBeFalse();
});
