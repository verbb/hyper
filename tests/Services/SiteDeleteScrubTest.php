<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Site as SiteLink;
use verbb\hyper\services\LinkTypeConfigs;

it('scrubs deleted site UIDs from Hyper field settings and content', function() {
    $sites = HyperFixtureFactory::ensureSites(3);
    $siteToDelete = $sites[2];

    $siteLinkConfig = HyperFixtureFactory::linkTypeConfig(SiteLink::class);
    $siteLinkConfig['sites'] = array_map(static fn($site) => $site->uid, $sites);

    $field = HyperFixtureFactory::hyperFieldWithLinkTypes([$siteLinkConfig]);
    $section = HyperFixtureFactory::entrySection($field);

    $entry = HyperFixtureFactory::plainEntry(
        $section,
        'Site link owner',
        [
            $field->handle => [
                HyperFixtureFactory::siteLinkPayload($siteToDelete, 'Secondary site'),
            ],
        ],
    );

    Craft::$app->getSites()->deleteSite($siteToDelete);

    $field = Craft::$app->fields->getFieldById($field->id);
    expect($field)->toBeInstanceOf(HyperField::class);

    $siteLinkType = null;

    foreach ($field->getLinkTypes() as $linkType) {
        if ($linkType instanceof SiteLink) {
            $siteLinkType = $linkType;
            break;
        }
    }

    expect($siteLinkType)->toBeInstanceOf(SiteLink::class);
    expect($siteLinkType->sites)->toBe([$sites[0]->uid, $sites[1]->uid]);

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $links = $entry->getFieldValue($field->handle);

    expect($links->getLinks())->toHaveCount(1);
    expect($links->getLinks()[0]->linkValue)->toBeNull();
    expect($links->getLinks()[0]->getLinkSite())->toBeNull();
});

it('scrubs deleted site UIDs from link type configs', function() {
    $sites = HyperFixtureFactory::ensureSites(3);
    $siteToDelete = $sites[2];

    $siteLinkConfig = HyperFixtureFactory::linkTypeConfig(SiteLink::class);
    $siteLinkConfig['sites'] = array_map(static fn($site) => $site->uid, $sites);

    $config = new \verbb\hyper\models\LinkTypeConfig([
        'name' => 'Site config',
        'handle' => HyperFixtureFactory::handle('hyperSiteConfig'),
        'linkTypes' => [$siteLinkConfig],
    ]);

    expect(Hyper::$plugin->getLinkTypeConfigs()->saveConfig($config))->toBeTrue();

    Craft::$app->getSites()->deleteSite($siteToDelete);

    $configData = Craft::$app->getProjectConfig()->get(LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $config->uid);
    $linkTypes = ProjectConfigHelper::unpackAssociativeArrays($configData['linkTypes'] ?? []);

    expect($linkTypes[0]['sites'] ?? null)->toBe([$sites[0]->uid, $sites[1]->uid]);

    Hyper::$plugin->getLinkTypeConfigs()->deleteConfig($config->uid);
});
