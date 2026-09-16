<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\base\Field;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

it('localizes category links during owner propagation and structure updates', function(bool $multiple) {
    [$primary, $secondary] = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'multipleLinks' => $multiple,
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
        'linkTypes' => [\verbb\hyper\links\Category::class],
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $group = new \craft\models\CategoryGroup([
        'name' => 'Hyper category localization', 'handle' => HyperFixtureFactory::handle('hyperTestCategoryGroup'),
    ]);
    $group->setSiteSettings(array_map(fn($site) => new \craft\models\CategoryGroup_SiteSettings([
        'siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => 'test-categories/{slug}', 'template' => '_hyper-test/entry',
    ]), Craft::$app->sites->getAllSites()));
    expect(Craft::$app->categories->saveGroup($group))->toBeTrue();
    try {
        $category = new \craft\elements\Category([
            'groupId' => $group->id, 'siteId' => $primary->id, 'title' => 'Category',
            'slug' => HyperFixtureFactory::handle('test-category'),
        ]);
        expect(Craft::$app->elements->saveElement($category))->toBeTrue();
        $payload = ['handle' => 'category', 'linkValue' => [$category->id], 'linkSiteId' => $primary->id];
        $owner = HyperFixtureFactory::plainEntry($section, 'Owner', [$field->handle => [$payload]], $primary);
        $check = function(int $count) use ($owner, $field, $category, $secondary) {
            Hyper::$plugin->linkRelations->resetRequestState();
            $localized = Entry::find()->id($owner->id)->siteId($secondary->id)->one();
            $links = $localized->getFieldValue($field->handle)->getLinks();
            $target = \craft\elements\Category::find()->id($category->id)->siteId($secondary->id)->one();
            expect($target)->not->toBeNull();
            expect($links)->toHaveCount($count);
            foreach ($links as $link) {
                expect($link->linkSiteId)->toBe($secondary->id);
                expect($link->getElement()?->siteId)->toBe($secondary->id);
                expect($link->getUrl())->toBe($target->getUrl());
            }
            $sites = (new \craft\db\Query())->select('targetSiteId')->from('{{%hyper_links}}')
                ->where(['ownerId' => $owner->id, 'ownerSiteId' => $secondary->id, 'fieldId' => $field->id])->column();
            expect(array_map('intval', $sites))->toBe(array_fill(0, $count, $secondary->id));
        };
        $check(1);
        if ($multiple) {
            $owner = Entry::find()->id($owner->id)->siteId($primary->id)->one();
            $links = $owner->getFieldValue($field->handle);
            $owner->setFieldValue($field->handle, $links->withLinks([
                ...$links->getLinks(), Hyper::$plugin->links->createLinkFromSerialized($field, $payload),
            ]));
            expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
            $check(2);
        }
    } finally {
        Craft::$app->categories->deleteGroup($group);
    }
})->with([false, true]);

it('preserves cleared translated text when a sibling adds another link', function(?string $cleared) {
    [$primary, $secondary] = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField([
        'multipleLinks' => true, 'translationMethod' => Field::TRANSLATION_METHOD_SITE, 'linkTypes' => [Url::class],
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $owner = HyperFixtureFactory::plainEntry($section, 'Owner', [
        $field->handle => [HyperFixtureFactory::urlLinkPayload('https://example.test', 'Source label')],
    ], $primary);
    $localized = HyperFixtureFactory::localizedEntryForSite($owner, $secondary);
    $links = $localized->getFieldValue($field->handle);
    expect($links->getLinks()[0]->getCustomLinkText())->toBe('Source label');
    $uid = $links->getLinks()[0]->uid;
    $links->getLinks()[0]->linkText = $cleared;
    $localized->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($localized))->toBeTrue();
    $reload = fn($site) => Entry::find()->id($owner->id)->siteId($site->id)->one();
    expect($reload($secondary)->getFieldValue($field->handle)->getLinks()[0]->getCustomLinkText())->toBeNull();
    $source = $reload($primary);
    $links = $source->getFieldValue($field->handle);
    $new = Hyper::$plugin->links->createLinkFromSerialized($field, HyperFixtureFactory::urlLinkPayload('https://example.test/new', 'New label'));
    $source->setFieldValue($field->handle, $links->withLinks([...$links->getLinks(), $new]));
    expect(Craft::$app->elements->saveElement($source))->toBeTrue();
    $translated = $reload($secondary)->getFieldValue($field->handle)->getLinks();
    expect($translated)->toHaveCount(2);
    expect($translated[0]->uid)->toBe($uid);
    expect($translated[0]->getCustomLinkText())->toBeNull();
    expect($translated[1]->getCustomLinkText())->toBe('New label');
})->with([null, '']);

it('keeps an empty translated custom-field bag when a sibling adds a link', function() {
    [$primary, $secondary] = HyperFixtureFactory::ensureSites(2);
    $caption = new \craft\fields\PlainText(['name' => 'Translated caption', 'handle' => HyperFixtureFactory::handle('hyperCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new \craft\fieldlayoutelements\CustomField($caption)]);
    $url->setFieldLayout($layout);
    $field = HyperFixtureFactory::hyperFieldWithLinkTypes([HyperFixtureFactory::linkTypeConfig($url)], [
        'multipleLinks' => true, 'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $payload = HyperFixtureFactory::urlLinkPayload('https://example.test', 'Source label') + ['fields' => [$caption->handle => 'Source caption']];
    $owner = HyperFixtureFactory::plainEntry($section, 'Owner', [$field->handle => [$payload]], $primary);
    $reload = fn($site) => Entry::find()->id($owner->id)->siteId($site->id)->one();
    $localized = $reload($secondary);
    $translated = $field->serializeValue($localized->getFieldValue($field->handle));
    $translated[0]['fields'] = [];
    $localized->setFieldValue($field->handle, $translated);
    expect(Craft::$app->elements->saveElement($localized))->toBeTrue();
    expect($reload($secondary)->getFieldValue($field->handle)->first()->getFieldValue($caption->handle))->toBeNull();

    $source = $reload($primary);
    $links = $source->getFieldValue($field->handle);
    expect($links->first()->getFieldValue($caption->handle))->toBe('Source caption');
    $new = Hyper::$plugin->links->createLinkFromSerialized($field, $payload);
    $source->setFieldValue($field->handle, $links->withLinks([...$links->getLinks(), $new]));
    expect(Craft::$app->elements->saveElement($source))->toBeTrue();
    $translated = $reload($secondary)->getFieldValue($field->handle)->getLinks();
    expect($translated)->toHaveCount(2);
    expect($translated[0]->getFieldValue($caption->handle))->toBeNull();
    expect($translated[1]->getFieldValue($caption->handle))->toBe('Source caption');
});

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
