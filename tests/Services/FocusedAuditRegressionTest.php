<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\fields\PlainText;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;
use verbb\hyper\migrations\MigrateCraftLinkContent;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\models\LinkCollection;

it('keeps cached entry publication status consistent with uncached resolution', function(string $status, bool $visible) {
    $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Publication target');
    $target->postDate = new DateTime($status === 'pending' ? '+1 day' : '-2 days');
    $target->expiryDate = $status === 'expired' ? new DateTime('-1 day') : null;
    $target->enabled = $status !== 'disabled';
    $target->setEnabledForSite($status !== 'site-disabled');
    expect(Craft::$app->elements->saveElement($target))->toBeTrue();
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [F::entryLinkPayload($target)]]);

    Hyper::$plugin->linkRelations->resetRequestState();
    $cold = Hyper::$plugin->links->createLinkFromSerialized($field, F::entryLinkPayload($target));
    expect($cold->getElement()?->id)->toBe($visible ? $target->id : null);

    Hyper::$plugin->linkRelations->resetRequestState();
    $queried = Entry::find()->id($owner->id)->siteId($owner->siteId)->one();
    $link = $queried->getFieldValue($field->handle)->getLinks()[0];
    expect($link->getElement()?->id)->toBe($visible ? $target->id : null);
    expect($link->getUrl() !== null)->toBe($visible);
    expect($link->getElement(null)?->id)->toBe($target->id);
    // An unrestricted lookup must not make the next default lookup unrestricted.
    expect($link->getElement()?->id)->toBe($visible ? $target->id : null);
})->with([
    ['pending', false], ['expired', false], ['disabled', false], ['site-disabled', false], ['live', true],
]);

it('uses the owner site for cached links regardless of earlier mixed-site queries', function() {
    [$primary, $secondary] = F::ensureSites(2);
    $previousSite = Craft::$app->sites->getCurrentSite();
    Craft::$app->sites->setCurrentSite($primary);
    try {
        $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
        $section = F::entrySection($field);
        $target = F::plainEntry($section, 'Primary-only target');
        $ownerSection = F::translatableEntrySection($field, 2);
        $primaryOwner = F::plainEntry($section, 'Primary owner', [$field->handle => [F::entryLinkPayload($target)]], $primary);
        $secondaryOwner = F::plainEntry($ownerSection, 'Secondary owner', [$field->handle => [F::entryLinkPayload($target)]], $secondary);

        Hyper::$plugin->linkRelations->resetRequestState();
        $coldOwner = Entry::find()->id($secondaryOwner->id)->siteId($secondary->id)->one();
        expect($coldOwner->getFieldValue($field->handle)->getLinks()[0]->getElement())->toBeNull();
        Hyper::$plugin->linkRelations->resetRequestState();
        Entry::find()->id($primaryOwner->id)->siteId($primary->id)->one();
        $warmOwner = Entry::find()->id($secondaryOwner->id)->siteId($secondary->id)->one();
        $link = $warmOwner->getFieldValue($field->handle)->getLinks()[0];
        expect($link->linkSiteId)->toBeNull();
        expect($link->getElement())->toBeNull();
        $link->linkSiteId = $primary->id;
        expect($link->getElement()?->siteId)->toBe($primary->id);
        $link->linkSiteId = null;
        expect($link->getElement())->toBeNull();
    } finally {
        Craft::$app->sites->setCurrentSite($previousSite);
    }
});

it('preserves migrated destination sites through rendering and relation indexing', function(string $kind, bool $explicit) {
    [$ownerSite, $targetSite] = F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = F::translatableEntrySection($field, 2);
    $targets = F::localizedEntryPair($section, $ownerSite, $targetSite, 'Destination');
    $owner = F::plainEntry($section, 'Owner', [], $ownerSite);
    $migration = $kind === 'native' ? new MigrateCraftLinkContent() : new MigrateTypedLinkContent();
    (new ReflectionProperty($migration, 'contentSiteId'))->setValue($migration, $ownerSite->id);
    $input = $kind === 'native'
        ? ['type' => 'entry', 'value' => '{entry:' . $targets['primary']->id . ($explicit ? '@' . $targetSite->id : '') . ':url}']
        : ['type' => 'entry', 'linkedId' => $targets['primary']->id, 'linkedSiteId' => $explicit ? $targetSite->id : null];
    $converted = $migration->convertModel($field, $input);
    $expectedSite = $explicit ? $targetSite : $ownerSite;
    expect($converted[0]['linkSiteId'])->toBe($expectedSite->id);
    $collection = new LinkCollection($field, $converted, $owner);
    expect($collection->getLinks()[0]->getElement()?->siteId)->toBe($expectedSite->id);
    expect($collection->getLinks()[0]->getUrl())->toBe(($explicit ? $targets['secondary'] : $targets['primary'])->getUrl());
    Hyper::$plugin->linkRelations->syncFromLinkCollection($field, $owner, $collection);
    $row = (new Query())->from('{{%hyper_links}}')->where(['ownerId' => $owner->id, 'ownerSiteId' => $ownerSite->id, 'fieldId' => $field->id])->one();
    expect((int)$row['targetSiteId'])->toBe($expectedSite->id);
})->with([['native', true], ['native', false], ['typed', true], ['typed', false]]);

it('keeps Linkit social types associated with their migrated settings', function() {
    $legacy = new PlainText(['name' => 'Linkit migration', 'handle' => F::handle('hyperTestLinkit')]);
    expect(Craft::$app->fields->saveField($legacy))->toBeTrue();
    $prefix = 'presseddigital\\linkit\\models\\';
    $settings = ['types' => [
        $prefix . 'Url' => ['enabled' => false],
        $prefix . 'Twitter' => ['enabled' => true, 'customPlaceholder' => 'Twitter URL'],
        $prefix . 'Facebook' => ['enabled' => true, 'customPlaceholder' => 'Facebook URL'],
    ]];
    $row = (new Query())->from('{{%fields}}')->where(['id' => $legacy->id])->one();
    $row['settings'] = json_encode($settings);
    $migration = new MigrateLinkitField();
    $migration->fields = [$row];
    $migration->processFieldSettings();
    Craft::$app->fields->refreshFields();
    $field = Craft::$app->fields->getFieldById($legacy->id);
    expect($field)->toBeInstanceOf(HyperField::class);
    $handles = [];
    foreach (['Twitter', 'Facebook'] as $type) {
        $url = 'https://' . strtolower($type) . '.com/CraftCMS';
        $converted = (new MigrateLinkitContent())->convertModel($field, ['type' => $prefix . $type, 'value' => $url]);
        $link = (new LinkCollection($field, $converted))->getLinks()[0];
        expect($link)->toBeInstanceOf(Url::class);
        expect($link->getUrl())->toBe($url);
        expect($link->placeholder)->toBe($type . ' URL');
        expect((new MigrateLinkitContent())->convertModel($field, $converted))->toBeNull();
        $handles[] = $converted[0]['linkTypeHandle'];
    }
    expect(array_unique($handles))->toHaveCount(2);
    expect($handles)->not->toContain('url');
});
