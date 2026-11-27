<?php

use craft\base\Field;
use craft\elements\Entry;
use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;

// Live Preview, Preview and share links render a draft or revision as `entry`, while relation
// rows only store canonical targets. Craft's own `relatedTo.targetElement` resolves the
// canonical ID, so the reverse lookup should list the same owners for every target state.
it('finds owners linking to the canonical entry when the target is a draft or revision', function(string $kind) {
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField(['translationMethod' => Field::TRANSLATION_METHOD_SITE]);
    $section = F::translatableEntrySection($field, 2);
    $target = F::plainEntry($section, 'Target', [], $primary);
    $other = F::plainEntry($section, 'Unlinked', [], $primary);
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [F::entryLinkPayload($target)]], $primary);

    // The secondary site keeps no link, so a match there would mean the target site was dropped.
    $ownerSecondary = F::localizedEntryForSite($owner, $secondary);
    $ownerSecondary->setFieldValue($field->handle, []);
    expect(Craft::$app->elements->saveElement($ownerSecondary))->toBeTrue();

    $creatorId = User::find()->admin()->one()->id;
    $derivativeId = fn(Entry $entry): int => match ($kind) {
        'canonical' => $entry->id,
        'revision' => Craft::$app->revisions->createRevision($entry, $creatorId, force: true),
        default => Craft::$app->drafts->createDraft($entry, $creatorId, 'Preview draft', provisional: $kind === 'provisional')->id,
    };
    $targetId = $derivativeId($target);
    $otherId = $derivativeId($other);

    $load = function(int $id, int $siteId) use ($kind): Entry {
        // getElementById already removes Craft's default draft and revision filters.
        $element = Craft::$app->elements->getElementById($id, Entry::class, $siteId);
        expect($element)->toBeInstanceOf(Entry::class);
        expect($element->getIsDerivative())->toBe($kind !== 'canonical');

        return $element;
    };
    $lookup = fn(Entry $element): array => array_map(
        fn(Entry $entry): string => $entry->id . ':' . $entry->siteId,
        Hyper::$plugin->linkRelations->getRelatedElementsQuery([
            'relatedTo' => ['field' => $field->handle, 'targetElement' => $element],
            'site' => '*',
        ])->all(),
    );

    expect([
        'primary' => $lookup($load($targetId, $primary->id)),
        'secondary' => $lookup($load($targetId, $secondary->id)),
        'unlinked' => $lookup($load($otherId, $primary->id)),
    ])->toBe([
        'primary' => [$owner->id . ':' . $primary->id],
        'secondary' => [],
        'unlinked' => [],
    ]);
})->with(['canonical', 'draft', 'provisional', 'revision']);
