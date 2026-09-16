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
