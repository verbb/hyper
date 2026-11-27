<?php

declare(strict_types=1);

use craft\db\Query;
use craft\migrations\BaseFieldMergeMigration;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;

it('carries the outgoing field’s reverse relations and draft baselines over when Hyper fields are merged', function() {
    $persisting = F::hyperField();
    $outgoing = F::hyperField();
    $persistingSection = F::entrySection($persisting);
    $outgoingSection = F::entrySection($outgoing);
    $target = F::plainEntry($persistingSection, 'Merge target');
    $persistingOwner = F::plainEntry($persistingSection, 'Persisting owner', [$persisting->handle => [F::entryLinkPayload($target)]]);
    $outgoingOwner = F::plainEntry($outgoingSection, 'Outgoing owner', [$outgoing->handle => [F::entryLinkPayload($target)]]);
    $draft = Craft::$app->getDrafts()->createDraft($outgoingOwner);

    $fieldIds = fn(array $where) => array_map('intval', (new Query())->select('fieldId')->from('{{%hyper_links}}')->where($where)->column());
    $baselineFieldIds = fn() => array_map('intval', (new Query())->select('fieldId')->from('{{%hyper_draft_baselines}}')->where(['draftId' => $draft->id])->column());
    $relatedIds = fn() => Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'relatedTo' => ['field' => $persisting->handle, 'targetElement' => $target],
    ])->status(null)->ids();

    expect($fieldIds(['ownerId' => $outgoingOwner->id]))->toBe([$outgoing->id])
        ->and($baselineFieldIds())->toBe([$outgoing->id])
        ->and($relatedIds())->toBe([$persistingOwner->id]);

    // Run the content migration `fields/merge` writes for the persisting and outgoing field.
    $migration = new BaseFieldMergeMigration();
    $migration->persistingFieldUid = $persisting->uid;
    $migration->outgoingFieldUid = $outgoing->uid;

    expect($migration->safeUp())->toBeTrue();

    expect($fieldIds(['ownerId' => $outgoingOwner->id]))->toBe([$persisting->id])
        ->and($fieldIds(['fieldId' => $outgoing->id]))->toBe([])
        ->and($fieldIds(['ownerId' => $persistingOwner->id]))->toBe([$persisting->id])
        ->and($baselineFieldIds())->toBe([$persisting->id]);

    $ids = $relatedIds();
    sort($ids);
    expect($ids)->toBe([$persistingOwner->id, $outgoingOwner->id]);
});
