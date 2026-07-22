<?php

declare(strict_types=1);

use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Category;
use verbb\hyper\links\Entry as EntryLink;

it('filters entry sources without uri formats when limiting sources', function() {
    $entriesService = Craft::$app->getEntries();
    $handle = HyperFixtureFactory::handle('hyperNoUriSection');
    $entryType = new EntryType([
        'name' => 'No URI Type',
        'handle' => $handle . 'Type',
        'hasTitleField' => true,
    ]);
    expect($entriesService->saveEntryType($entryType))->toBeTrue();

    $section = new Section([
        'name' => 'No URI Section',
        'handle' => $handle,
        'type' => Section::TYPE_CHANNEL,
    ]);
    $section->setEntryTypes([$entryType]);
    $section->setSiteSettings([
        new Section_SiteSettings([
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'enabledByDefault' => true,
            'hasUrls' => false,
            'uriFormat' => null,
        ]),
    ]);
    expect($entriesService->saveSection($section))->toBeTrue();

    $savedSection = $entriesService->getSectionByHandle($handle);
    expect($savedSection)->not->toBeNull();

    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);
    $link->limitSourcesToSectionsWithUri = true;
    $link->sources = '*';

    $sourceKeys = array_column($link->getSourceOptions(), 'value');

    expect($sourceKeys)->not->toContain('section:' . $savedSection->uid);
});

it('does not limit entry sources unless the link type option is enabled', function() {
    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);
    $link->sources = '*';

    expect($link->shouldLimitSourcesToElementsWithUri())->toBeFalse();

    $link->limitSourcesToSectionsWithUri = true;

    expect($link->shouldLimitSourcesToElementsWithUri())->toBeTrue();
});

it('allows categories without uris when allowElementsWithoutUri is enabled', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Category::class);
    $link->limitSourcesToSectionsWithUri = true;
    $link->allowElementsWithoutUri = true;

    expect($link->shouldLimitSourcesToElementsWithUri())->toBeFalse();
});

it('appends url suffix fragments to element link urls', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::plainEntry($section, 'About page');

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'linkTypeHandle' => 'default-verbb-hyper-links-entry',
        'linkValue' => [$target->id],
        'linkSiteId' => $target->siteId,
        'urlSuffix' => '#team',
    ]);

    expect($link)->not->toBeNull();
    expect($link->urlSuffix)->toBe('#team');
    expect($link->getUrl())->toBe($target->getUrl() . '#team');
});
