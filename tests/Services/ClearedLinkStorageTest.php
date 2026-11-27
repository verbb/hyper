<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\conditions\EmptyFieldConditionRule;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Url;
use verbb\hyper\services\DraftConflicts;

function hyperClearedLinkStored(Entry $entry, HyperField $field): mixed
{
    $content = json_decode((string)(new \craft\db\Query())
        ->select('content')
        ->from(\craft\db\Table::ELEMENTS_SITES)
        ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])
        ->scalar(), true) ?? [];
    $uid = $entry->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;

    return array_key_exists($uid, $content) ? $content[$uid] : 'missing';
}

/**
 * The CP posts the link state as JSON, and always includes the new window toggle,
 * so a link cleared in the CP arrives as its type, ID and new window choice.
 */
function hyperClearedLinkPost(Entry $entry, HyperField $field, bool $newWindow = false): string
{
    $link = $entry->getFieldValue($field->handle)->getLinks()[0];

    return Json::encode([['linkTypeHandle' => $link->handle, 'uid' => $link->uid, 'newWindow' => $newWindow]]);
}

function hyperClearedLinkIds(array $ids): array
{
    $ids = array_map('intval', $ids);
    sort($ids);

    return $ids;
}

it('stores nothing for a link cleared in the control panel', function(bool $defaultNewWindow) {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $field->defaultNewWindow = $defaultNewWindow;
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $section = F::entrySection($field);
    $linked = F::plainEntry($section, 'Linked', [$field->handle => [F::urlLinkPayload('https://example.test/linked', 'Linked')]]);
    $cleared = F::plainEntry($section, 'Cleared', [$field->handle => [F::urlLinkPayload('https://example.test/cleared', 'Cleared')]]);

    // The untouched toggle posts the field default.
    $cleared->setFieldValue($field->handle, hyperClearedLinkPost($cleared, $field, $defaultNewWindow));
    expect($field->isValueEmpty($cleared->getFieldValue($field->handle), $cleared))->toBeTrue();
    expect(Craft::$app->getElements()->saveElement($cleared))->toBeTrue();

    expect(hyperClearedLinkStored($cleared, $field))->toBe([]);
    $reloaded = Entry::find()->id($cleared->id)->status(null)->one();
    expect($reloaded->getFieldValue($field->handle)->getLinks())->toBe([]);
    expect($field->isValueEmpty($reloaded->getFieldValue($field->handle), $reloaded))->toBeTrue();

    $query = fn(mixed $value) => hyperClearedLinkIds(Entry::find()->sectionId($section->id)->status(null)->{$field->handle}($value)->ids());
    expect($query(':empty:'))->toBe([$cleared->id]);
    expect($query(':notempty:'))->toBe([$linked->id]);

    // Craft's operator constants are protected, so use the stored operator values.
    $entries = Entry::find()->sectionId($section->id)->status(null)->all();

    foreach (['empty' => [$cleared->id], 'notempty' => [$linked->id]] as $operator => $ids) {
        $condition = Entry::createCondition();
        $condition->addConditionRule($condition->createConditionRule([
            'class' => EmptyFieldConditionRule::class,
            'fieldUid' => $field->uid,
            'operator' => $operator,
        ]));
        $query = Entry::find()->sectionId($section->id)->status(null);
        $condition->modifyQuery($query);
        $matched = array_map(fn(Entry $entry) => $entry->id, array_values(array_filter($entries, fn(Entry $entry) => $condition->matchElement($entry))));

        expect(hyperClearedLinkIds($query->ids()))->toBe($ids, $operator);
        expect(hyperClearedLinkIds($matched))->toBe($ids, $operator);
    }
})->with([
    'default same window' => [false],
    'default new window' => [true],
]);

it('keeps links with entered content and drops only the cleared ones', function() {
    $field = F::hyperField(['linkTypes' => [Url::class, Passive::class], 'multipleLinks' => true]);
    $url = F::urlLinkPayload('https://example.test/kept', 'Kept')['handle'];
    $passive = F::passiveLinkPayload()['handle'];
    $owner = F::plainEntry(F::entrySection($field), 'Mixed', [$field->handle => [F::urlLinkPayload('https://example.test/original')]]);

    $owner->setFieldValue($field->handle, Json::encode([
        ['linkTypeHandle' => $url, 'newWindow' => false],
        ['linkTypeHandle' => $url, 'newWindow' => false, 'linkValue' => 'https://example.test/kept', 'linkText' => 'Kept'],
        ['linkTypeHandle' => $url, 'newWindow' => false, 'linkText' => 'Label only'],
        ['linkTypeHandle' => $url, 'newWindow' => false, 'classes' => 'classes-only'],
        ['linkTypeHandle' => $url, 'newWindow' => false, 'customAttributes' => [['attribute' => 'data-kept', 'value' => '1']]],
        ['linkTypeHandle' => $url, 'newWindow' => true],
        ['linkTypeHandle' => $passive, 'newWindow' => false],
        ['linkTypeHandle' => $passive, 'newWindow' => false, 'linkText' => 'Heading'],
        ['linkTypeHandle' => $url],
    ]));
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();

    $links = Entry::find()->id($owner->id)->status(null)->one()->getFieldValue($field->handle)->getLinks();
    $summary = array_map(fn($link) => [$link->handle, $link->linkValue, $link->linkText, $link->classes, $link->newWindow], $links);

    expect($summary)->toBe([
        [$url, 'https://example.test/kept', 'Kept', null, false],
        [$url, null, 'Label only', null, false],
        [$url, null, null, 'classes-only', false],
        [$url, null, null, null, false],
        // A new window choice that differs from the field default is kept, as for #146.
        [$url, null, null, null, true],
        [$passive, null, 'Heading', null, false],
    ]);
    expect($links[3]->customAttributes)->not->toBeEmpty();
});

it('keeps a cleared link in a list synced across sites', function() {
    F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true, 'syncLinksAcrossSites' => true, 'translationMethod' => \craft\base\Field::TRANSLATION_METHOD_SITE]);
    $owner = F::plainEntry(F::translatableEntrySection($field, 2), 'Synced', [$field->handle => [F::urlLinkPayload('https://example.test/synced', 'Synced')]]);

    // The blank row keeps the list's structure and the other sites' translations.
    $owner->setFieldValue($field->handle, hyperClearedLinkPost($owner, $field));
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();
    expect(hyperClearedLinkStored($owner, $field))->toHaveCount(1);

    // As before, a blank link set programmatically still makes a synced list count as empty.
    expect($field->isValueEmpty($field->normalizeValue([['type' => Url::class, 'handle' => Url::typeKey(), 'linkValue' => '']], $owner), $owner))->toBeTrue();
});

it('validates only the links that saving keeps', function() {
    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]);
    $field->minLinks = 1;
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $owner = F::plainEntry(F::entrySection($field), 'Minimum', [$field->handle => [F::urlLinkPayload('https://example.test/original')]]);
    $owner->setAuthorIds([User::find()->admin()->one()->id]);

    $owner->setFieldValue($field->handle, hyperClearedLinkPost($owner, $field));
    $owner->setScenario(Element::SCENARIO_LIVE);
    expect(Craft::$app->getElements()->saveElement($owner))->toBeFalse();
    expect($owner->getErrors($field->handle))->not->toBeEmpty();
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('https://example.test/original');
});

it('saves a link cleared in the control panel when Link Text is required', function() {
    $prototype = new Url();
    $layout = Url::getDefaultFieldLayout();
    $layout->getField('linkText')->required = true;
    $prototype->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($prototype)]);
    $owner = F::plainEntry(F::entrySection($field), 'Required label', [$field->handle => [['handle' => $prototype->handle, 'linkValue' => 'https://example.test/labelled', 'linkText' => 'Labelled']]]);
    $owner->setAuthorIds([User::find()->admin()->one()->id]);

    $owner->setFieldValue($field->handle, hyperClearedLinkPost($owner, $field));
    $owner->setScenario(Element::SCENARIO_LIVE);
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue(json_encode($owner->getErrors()));
    expect(hyperClearedLinkStored($owner, $field))->toBe([]);
});

it('applies a draft whose baseline was recorded with a cleared link before 3.2', function() {
    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]);
    $owner = F::plainEntry(F::entrySection($field), 'Stored blank', [$field->handle => [F::urlLinkPayload('/a')]]);
    $field = $owner->getFieldLayout()->getFieldByHandle($field->handle);
    $link = $owner->getFieldValue($field->handle)->getLinks()[0];

    // Earlier releases stored a cleared link. Seed that shape directly.
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', [
        'content' => new \yii\db\JsonExpression([$field->layoutElement->uid => [
            ['linkTypeHandle' => $link->handle, 'uid' => $link->uid, 'linkValue' => '/a'],
            ['linkTypeHandle' => $link->handle, 'uid' => \craft\helpers\StringHelper::UUID(), 'newWindow' => false],
        ]]),
    ], ['elementId' => $owner->id, 'siteId' => $owner->siteId])->execute();
    $owner = Entry::find()->id($owner->id)->one();
    $draft = Craft::$app->drafts->createDraft($owner, User::find()->admin()->one()->id, 'Before 3.2');

    // Record the fingerprint those releases took, which included the cleared link.
    $hash = (new ReflectionMethod(DraftConflicts::class, '_hash'))->getClosure();
    $links = Hyper::$plugin->getLinks();
    $fingerprint = fn() => $hash($field, Entry::find()->id($owner->id)->one());
    $baseline = method_exists($links, 'withClearedLinksKept') ? $links->withClearedLinksKept($fingerprint) : $fingerprint();
    Craft::$app->db->createCommand()->update('{{%hyper_draft_baselines}}', ['baseline' => $baseline], [
        'draftId' => $draft->id, 'siteId' => $draft->siteId, 'layoutUid' => $field->layoutElement->uid,
    ])->execute();

    $links = $draft->getFieldValue($field->handle)->getLinks();
    $links[0]->linkValue = '/edited';
    $draft->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
    Craft::$app->drafts->applyDraft(Entry::find()->id($draft->id)->drafts(true)->status(null)->one());

    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/edited');
});
