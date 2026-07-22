<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\base\Field;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

it('propagates multi-link structure to sibling sites while preserving translated link text', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'multipleLinks' => true,
        'linkTypes' => [Url::class, EntryLink::class],
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $targets = HyperFixtureFactory::localizedEntryPair($section, $sites[0], $sites[1], 'Nav target');

    $ownerSiteA = HyperFixtureFactory::plainEntry(
        $section,
        'Nav owner A',
        [
            $field->handle => [
                HyperFixtureFactory::urlLinkPayload('https://example.test/home', 'Home'),
                HyperFixtureFactory::entryLinkPayload($targets['primary'], 'About'),
            ],
        ],
        $sites[0],
    );

    $ownerSiteB = HyperFixtureFactory::localizedEntryForSite($ownerSiteA, $sites[1]);

    /** @var \verbb\hyper\models\LinkCollection $linksB */
    $linksB = $ownerSiteB->getFieldValue($field->handle);
    expect($linksB->getLinks())->toHaveCount(2);
    expect($linksB->getLinks()[0]->getCustomLinkText())->toBe('Home');
    expect($linksB->getLinks()[1]->getCustomLinkText())->toBe('About');

    $linksB->getLinks()[0]->linkText = 'Accueil';
    $ownerSiteB->setFieldValue($field->handle, $linksB);
    Craft::$app->getElements()->saveElement($ownerSiteB);

    $ownerSiteA = Entry::find()->id($ownerSiteA->id)->siteId($sites[0]->id)->status(null)->one();
    $linksA = $ownerSiteA->getFieldValue($field->handle);
    $ownerSiteA->setFieldValue($field->handle, $linksA->withLinks([
        ...$linksA->getLinks(),
        Hyper::$plugin->getLinks()->createLinkFromSerialized($field, HyperFixtureFactory::urlLinkPayload('https://example.test/contact', 'Contact')),
    ]));
    Craft::$app->getElements()->saveElement($ownerSiteA);

    $ownerSiteB = Entry::find()->id($ownerSiteB->id)->siteId($sites[1]->id)->status(null)->one();
    $linksB = $ownerSiteB->getFieldValue($field->handle);

    expect($linksB->getLinks())->toHaveCount(3);
    expect($linksB->getLinks()[0]->getCustomLinkText())->toBe('Accueil');
    expect($linksB->getLinks()[1]->getElement()?->id)->toBe($targets['secondary']->id);
    expect($linksB->getLinks()[2]->getCustomLinkText())->toBe('Contact');
});

it('localizes entry links to sibling sites on initial entry creation', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $targets = HyperFixtureFactory::localizedEntryPair($section, $sites[0], $sites[1], 'Linked page');

    $ownerSiteA = HyperFixtureFactory::plainEntry(
        $section,
        'Owner on site A',
        [
            $field->handle => [
                HyperFixtureFactory::entryLinkPayload($targets['primary'], 'Read more'),
            ],
        ],
        $sites[0],
    );

    $ownerSiteB = HyperFixtureFactory::localizedEntryForSite($ownerSiteA, $sites[1]);

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $linkB = $ownerSiteB->getFieldValue($field->handle)->getLinks()[0];
    expect($linkB->linkValue)->toContain($targets['primary']->id);
    expect($linkB->getElement()?->id)->toBe($targets['secondary']->id);
    expect($linkB->getElement()?->siteId)->toBe($sites[1]->id);
    expect($linkB->getCustomLinkText())->toBe('Read more');
});

it('resolves entry links to the localized target for the current site', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'multipleLinks' => true,
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $targets = HyperFixtureFactory::localizedEntryPair($section, $sites[0], $sites[1], 'Linked page');

    $owner = HyperFixtureFactory::plainEntry(
        $section,
        'Owner on site B',
        [
            $field->handle => [
                HyperFixtureFactory::entryLinkPayload($targets['primary'], 'Read more'),
            ],
        ],
        $sites[1],
    );

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $link = $owner->getFieldValue($field->handle)->getLinks()[0];
    expect($link->linkValue)->toContain($targets['primary']->id);
    expect($link->getElement()?->id)->toBe($targets['secondary']->id);
    expect($link->getElement()?->siteId)->toBe($sites[1]->id);
});

it('does not sync multi-link structure when the field was not edited', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'multipleLinks' => true,
        'linkTypes' => [Url::class],
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);

    $ownerSiteA = HyperFixtureFactory::plainEntry(
        $section,
        'Nav owner A',
        [
            $field->handle => [
                HyperFixtureFactory::urlLinkPayload('https://example.test/home', 'Home'),
                HyperFixtureFactory::urlLinkPayload('https://example.test/about', 'About'),
            ],
        ],
        $sites[0],
    );

    $ownerSiteB = HyperFixtureFactory::localizedEntryForSite($ownerSiteA, $sites[1]);

    $linksB = $ownerSiteB->getFieldValue($field->handle);
    $linksB->getLinks()[0]->linkText = 'Accueil';
    $ownerSiteB->setFieldValue($field->handle, $linksB);
    Craft::$app->getElements()->saveElement($ownerSiteB);

    $ownerSiteA = Entry::find()->id($ownerSiteA->id)->siteId($sites[0]->id)->status(null)->one();
    $ownerSiteA->title = 'Nav owner A updated';
    Craft::$app->getElements()->saveElement($ownerSiteA);

    $ownerSiteB = Entry::find()->id($ownerSiteB->id)->siteId($sites[1]->id)->status(null)->one();
    $linksB = $ownerSiteB->getFieldValue($field->handle);

    expect($linksB->getLinks())->toHaveCount(2);
    expect($linksB->getLinks()[0]->getCustomLinkText())->toBe('Accueil');
    expect($linksB->getLinks()[1]->getCustomLinkText())->toBe('About');
});

it('treats links with attributes but no target as non-empty for propagation checks', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'multipleLinks' => true,
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);

    $ownerSiteA = HyperFixtureFactory::plainEntry(
        $section,
        'Owner A',
        [
            $field->handle => [
                HyperFixtureFactory::urlLinkPayload('', 'Label only'),
            ],
        ],
        $sites[0],
    );

    $ownerSiteB = HyperFixtureFactory::localizedEntryForSite($ownerSiteA, $sites[1]);

    $linksB = $ownerSiteB->getFieldValue($field->handle);
    expect($field->isValueEmpty($linksB, $ownerSiteB))->toBeFalse();
    expect($linksB->isEmpty())->toBeFalse();
    expect($linksB->getLinks()[0]->getCustomLinkText())->toBe('Label only');
});

it('localizes element links when propagateValue is called for shared fields', function() {
    $sites = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'translationMethod' => Field::TRANSLATION_METHOD_NONE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $targets = HyperFixtureFactory::localizedEntryPair($section, $sites[0], $sites[1], 'Shared target');

    $ownerSiteA = HyperFixtureFactory::plainEntry(
        $section,
        'Shared owner A',
        [
            $field->handle => [
                HyperFixtureFactory::entryLinkPayload($targets['primary'], 'Shared link'),
            ],
        ],
        $sites[0],
    );

    $ownerSiteB = HyperFixtureFactory::localizedEntryForSite($ownerSiteA, $sites[1]);

    $field->propagateValue($ownerSiteA, $ownerSiteB);

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $linkB = $ownerSiteB->getFieldValue($field->handle)->getLinks()[0];
    expect($linkB->getElement()?->id)->toBe($targets['secondary']->id);
    expect($linkB->getElement()?->siteId)->toBe($sites[1]->id);
});
