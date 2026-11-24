<?php

declare(strict_types=1);

use craft\db\Query;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\content\Change;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

// Hyper 2 gave each built-in type the handle `default-<kebab-fqcn>` with `isCustom: false`.
// "New link type" cloned a class as a custom type with a random 10-character handle,
// and any row could be sorted first. Content stored the type's exact handle.
const LEGACY_CAMPAIGN_HANDLE = 'q7HcX2mLp9';
const LEGACY_PRODUCT_HANDLE = 'Rt4nW8zKa1';

function legacyLinkTypeRow(string $class, string $label, string $handle, bool $isCustom, ?CustomField $extra = null): array
{
    $layout = $class::getDefaultFieldLayout();
    $layout->type = $class;

    if ($extra) {
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), $extra]);
    }

    // Hyper 2 saved each link layout as its own field layout row keyed by `layoutUid`.
    $layoutConfig = $layout->getConfig();
    $saved = FieldLayout::createFromConfig($layoutConfig);
    $saved->type = $class;
    $saved->uid = StringHelper::UUID();
    Craft::$app->getFields()->saveLayout($saved);

    $row = [
        'type' => $class,
        'label' => $label,
        'handle' => $handle,
        'enabled' => true,
        'isCustom' => $isCustom,
        'layoutUid' => $saved->uid,
        'layoutConfig' => $layoutConfig,
    ];

    return $row + ($class === Url::class
        ? ['placeholder' => null]
        : ['sources' => '*', 'selectionLabel' => null, 'showSiteMenu' => false, 'allowElementsWithoutUri' => false]);
}

/**
 * A Hyper field as an install upgraded from Hyper 2 holds it: the verbatim Hyper 2
 * settings in the fields table and project config, before any Hyper 3 field save.
 */
function legacyUpgradedHyperField(bool $customFirst): array
{
    $caption = new PlainText(['name' => 'Caption', 'handle' => F::handle('legacyCaption')]);
    expect(Craft::$app->getFields()->saveField($caption))->toBeTrue();
    $captionPlacement = new CustomField($caption, ['uid' => StringHelper::UUID()]);

    $campaign = legacyLinkTypeRow(Url::class, 'Campaign URL', LEGACY_CAMPAIGN_HANDLE, true);
    $url = legacyLinkTypeRow(Url::class, 'URL', 'default-verbb-hyper-links-url', false, $captionPlacement);
    $product = legacyLinkTypeRow(EntryLink::class, 'Product Page', LEGACY_PRODUCT_HANDLE, true);
    $entry = legacyLinkTypeRow(EntryLink::class, 'Entry', 'default-verbb-hyper-links-entry', false);
    $linkTypes = $customFirst ? [$campaign, $url, $product, $entry] : [$url, $campaign, $entry, $product];

    $field = F::hyperField(['multipleLinks' => true]);
    $settings = [
        'defaultLinkType' => 'default-verbb-hyper-links-url',
        'newWindow' => true,
        'defaultNewWindow' => false,
        'multipleLinks' => true,
        'minLinks' => null,
        'maxLinks' => null,
        'fieldLayoutId' => null,
        'migrationData' => [],
        'linkTypes' => $linkTypes,
    ];

    Craft::$app->getDb()->createCommand()
        ->update('{{%fields}}', ['settings' => Json::encode($settings)], ['id' => $field->id])
        ->execute();
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->muteEvents = true;
    $projectConfig->set("fields.{$field->uid}.settings", $settings);
    $projectConfig->muteEvents = false;
    Craft::$app->getFields()->refreshFields();

    $field = Craft::$app->getFields()->getFieldByHandle($field->handle);
    expect($field)->toBeInstanceOf(HyperField::class);

    return [$field, $caption, $captionPlacement->uid];
}

function legacyContentRows(string $captionPlacementUid, int $targetId, int $siteId): array
{
    // Hyper 2 Link::getSerializedValues(): type, exact handle, and layout values keyed by placement UID.
    return [
        ['type' => Url::class, 'handle' => 'default-verbb-hyper-links-url', 'newWindow' => false, 'linkValue' => 'https://example.test/builtin', 'linkText' => 'Built-in URL link', 'fields' => [$captionPlacementUid => 'Built-in caption']],
        ['type' => Url::class, 'handle' => LEGACY_CAMPAIGN_HANDLE, 'newWindow' => false, 'linkValue' => 'https://example.test/campaign', 'linkText' => 'Campaign link'],
        ['type' => EntryLink::class, 'handle' => 'default-verbb-hyper-links-entry', 'newWindow' => false, 'linkValue' => [$targetId], 'linkText' => 'Built-in entry link', 'linkSiteId' => $siteId],
        ['type' => EntryLink::class, 'handle' => LEGACY_PRODUCT_HANDLE, 'newWindow' => false, 'linkValue' => [$targetId], 'linkText' => 'Product link', 'linkSiteId' => $siteId],
    ];
}

function legacyUpgradedOwner(HyperField $field, string $captionPlacementUid): Entry
{
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Legacy target');
    $owner = F::plainEntry($section, 'Legacy owner');
    $placement = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $rows = legacyContentRows($captionPlacementUid, $target->id, $owner->siteId);

    Craft::$app->getDb()->createCommand()
        ->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$placement => $rows])], ['elementId' => $owner->id, 'siteId' => $owner->siteId])
        ->execute();
    Craft::$app->getElements()->invalidateCachesForElementType(Entry::class);

    // The Hyper 3 upgrade rebuilds link relations from stored content.
    Hyper::$plugin->getContent()->reconcileRelations($field);

    return Entry::find()->id($owner->id)->status(null)->one();
}

function legacyRelationHandles(Entry $owner): array
{
    return (new Query())
        ->select(['linkTypeHandle'])
        ->from('{{%hyper_links}}')
        ->where(['ownerId' => $owner->id, 'ownerSiteId' => $owner->siteId])
        ->orderBy(['sortOrder' => SORT_ASC])
        ->column();
}

it('resolves Hyper 2 built-in handles to the built-in type when a custom type of its class sorts first', function(bool $customFirst) {
    [$field] = legacyUpgradedHyperField($customFirst);

    // The settings side renamed the built-in rows and kept the custom handles.
    expect(array_column(array_map(fn($type) => ['handle' => $type->handle], $field->getLinkTypes()), 'handle'))
        ->toEqualCanonicalizing(['url', LEGACY_CAMPAIGN_HANDLE, 'entry', LEGACY_PRODUCT_HANDLE]);

    $url = $field->getLinkTypeByHandle('default-verbb-hyper-links-url');
    $entry = $field->getLinkTypeByHandle('default-verbb-hyper-links-entry');

    expect($url->handle)->toBe('url')->and($url->isCustom)->toBeFalse();
    expect($entry->handle)->toBe('entry')->and($entry->isCustom)->toBeFalse();
})->with(['custom types first' => [true], 'built-in types first' => [false]]);

it('keeps upgraded Hyper 2 links on their own types through render and save', function(bool $customFirst) {
    [$field, $caption, $captionPlacementUid] = legacyUpgradedHyperField($customFirst);
    $owner = legacyUpgradedOwner($field, $captionPlacementUid);

    $expected = ['url', LEGACY_CAMPAIGN_HANDLE, 'entry', LEGACY_PRODUCT_HANDLE];
    $links = $owner->getFieldValue($field->handle)->getLinks();

    expect(array_map(fn($link) => $link->handle, $links))->toBe($expected);
    expect($links[0]->getFieldValue($caption->handle))->toBe('Built-in caption');
    expect(legacyRelationHandles($owner))->toBe(['entry', LEGACY_PRODUCT_HANDLE]);

    // The first dirty save persists the resolved identity.
    $owner->setFieldValue($field->handle, $owner->getFieldValue($field->handle));
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();

    $placement = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $stored = Json::decodeIfJson((new Query())
        ->select(['content'])
        ->from('{{%elements_sites}}')
        ->where(['elementId' => $owner->id, 'siteId' => $owner->siteId])
        ->scalar());

    expect(array_column($stored[$placement], 'linkTypeHandle'))->toBe($expected);
    expect($stored[$placement][0]['fields'][$captionPlacementUid] ?? null)->toBe('Built-in caption');

    $saved = Entry::find()->id($owner->id)->status(null)->one()->getFieldValue($field->handle)->getLinks();
    expect($saved[0]->getFieldValue($caption->handle))->toBe('Built-in caption');
    expect(legacyRelationHandles($owner))->toBe(['entry', LEGACY_PRODUCT_HANDLE]);
})->with(['custom types first' => [true], 'built-in types first' => [false]]);

it('locates layout values of Hyper 2 built-in links in raw content traversal', function(bool $customFirst) {
    [$field, $caption, $captionPlacementUid] = legacyUpgradedHyperField($customFirst);
    $schema = Hyper::$plugin->getContent()->getRawContentAdapter()->captureSchema($field);

    expect($schema['handleAliases']['default-verbb-hyper-links-url'])->toBe('url');
    expect($schema['handleAliases']['default-verbb-hyper-links-entry'])->toBe('entry');

    $api = Hyper::$plugin->getContent();
    $map = $api->captureFieldLocations($caption->uid);
    $rows = legacyContentRows($captionPlacementUid, 1, 1);
    $out = $api->transformValue($rows, $field->uid, $map, fn() => Change::replace('Updated caption'));

    expect($out['matched'])->toBe(1)->and($out['changed'])->toBe(1);
    expect($out['value'][0]['fields'][$captionPlacementUid])->toBe('Updated caption');
    // Raw traversal keeps the stored identity.
    expect($out['value'][0]['handle'])->toBe('default-verbb-hyper-links-url');
})->with(['custom types first' => [true], 'built-in types first' => [false]]);

it('falls back to the first type of the class when no built-in type exists', function() {
    $first = F::linkTypeConfig(Url::class);
    $first['handle'] = 'campaignUrl';
    $first['isCustom'] = true;
    $second = F::linkTypeConfig(Url::class);
    $second['handle'] = 'promoUrl';
    $second['isCustom'] = true;
    $field = F::hyperFieldWithLinkTypes([$first, $second]);

    expect($field->getLinkTypeByHandle('default-verbb-hyper-links-url')?->handle)->toBe('campaignUrl');

    $schema = Hyper::$plugin->getContent()->getRawContentAdapter()->captureSchema($field);
    expect($schema['handleAliases']['default-verbb-hyper-links-url'])->toBe('campaignUrl');
});
