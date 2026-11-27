<?php

declare(strict_types=1);

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;

it('points element links at the prevailing element when Craft merges elements', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $merged = F::plainEntry($section, 'Merged target');
    $prevailing = F::plainEntry($section, 'Prevailing target');
    $other = F::plainEntry($section, 'Other target');
    $url = 'https://example.test/' . $merged->id;

    $owner = F::plainEntry($section, 'Merge owner', [$field->handle => [
        F::entryLinkPayload($merged),
        F::entryLinkPayload($other),
        F::urlLinkPayload($url),
    ]]);
    $draft = Craft::$app->getDrafts()->createDraft($owner);
    $revisionId = Craft::$app->getRevisions()->createRevision($owner);

    ['matrix' => $matrix, 'blockEntryType' => $blockType] = F::matrixFieldWithHyper($field);
    $matrixOwner = F::entryWithMatrixHyperLink(F::entrySectionWithField($matrix), $matrix, $field, $blockType, [F::entryLinkPayload($merged)]);
    $block = Entry::find()->ownerId($matrixOwner->id)->fieldId($matrix->id)->status(null)->one();

    // Content saved before Hyper 3 can hold a single string ID.
    $legacyOwner = F::plainEntry($section, 'Legacy owner');
    $legacyUid = $legacyOwner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', [
        'content' => new yii\db\JsonExpression([$legacyUid => [['linkTypeHandle' => EntryLink::typeKey(), 'linkValue' => (string)$merged->id]]]),
    ], ['elementId' => $legacyOwner->id])->execute();

    $stored = function(int $elementId, ?Entry $layoutOwner = null) use ($field, $owner): array {
        $uid = ($layoutOwner ?? $owner)->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
        $content = Json::decode((string)(new Query())->select('content')->from('{{%elements_sites}}')->where(['elementId' => $elementId])->scalar());

        return array_column($content[$uid] ?? [], 'linkValue');
    };
    $targets = fn(int $ownerId) => array_map('intval', (new Query())->select('targetId')->from('{{%hyper_links}}')->where(['ownerId' => $ownerId])->orderBy('sortOrder')->column());

    expect($stored($owner->id))->toBe([[$merged->id], [$other->id], $url])
        ->and($stored($draft->id))->toBe([[$merged->id], [$other->id], $url])
        ->and($stored($revisionId))->toBe([[$merged->id], [$other->id], $url])
        ->and($stored($block->id, $block))->toBe([[$merged->id]])
        ->and($targets($owner->id))->toBe([$merged->id, $other->id])
        ->and($targets($block->id))->toBe([$merged->id]);

    expect(Craft::$app->getElements()->mergeElements($merged, $prevailing))->toBeTrue();

    expect($stored($owner->id))->toBe([[$prevailing->id], [$other->id], $url])
        ->and($stored($draft->id))->toBe([[$prevailing->id], [$other->id], $url])
        ->and($stored($revisionId))->toBe([[$prevailing->id], [$other->id], $url])
        ->and($stored($block->id, $block))->toBe([[$prevailing->id]])
        ->and($stored($legacyOwner->id))->toBe([(string)$prevailing->id])
        ->and($targets($owner->id))->toBe([$prevailing->id, $other->id])
        ->and($targets($block->id))->toBe([$prevailing->id]);

    $fresh = Entry::find()->id($owner->id)->status(null)->one();
    expect($fresh->getFieldValue($field->handle)->getLinks()[0]->getElement()?->id)->toBe($prevailing->id);

    $related = Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'relatedTo' => ['field' => $field->handle, 'targetElement' => $prevailing],
    ])->status(null)->ids();
    sort($related);
    expect($related)->toBe([$owner->id, $block->id]);
});

it('applies a draft that edited a merged element’s link without reporting a conflict', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $merged = F::plainEntry($section, 'Merged target');
    $prevailing = F::plainEntry($section, 'Prevailing target');
    $owner = F::plainEntry($section, 'Merge owner', [$field->handle => [F::entryLinkPayload($merged, 'Old text')]]);

    $draft = Craft::$app->getDrafts()->createDraft($owner);
    $draft = Entry::find()->id($draft->id)->drafts(true)->status(null)->one();
    $draft->setFieldValue($field->handle, [F::entryLinkPayload($merged, 'New text')]);
    expect(Craft::$app->getElements()->saveElement($draft))->toBeTrue();

    expect(Craft::$app->getElements()->mergeElements($merged, $prevailing))->toBeTrue();

    $draft = Entry::find()->id($draft->id)->drafts(true)->status(null)->one();
    Craft::$app->getDrafts()->applyDraft($draft);

    $link = Entry::find()->id($owner->id)->status(null)->one()->getFieldValue($field->handle)->getLinks()[0];
    expect($link->linkText)->toBe('New text')
        ->and($link->getElement()?->id)->toBe($prevailing->id);
});
