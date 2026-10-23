<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\m250703_010000_backfill_hyper_links_from_element_cache as Backfill;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;
use verbb\hyper\records\LinkRelation;

it('preserves multisite derivative content while rebuilding only canonical owner relations', function(string $kind, string $migrationClass, bool $loadable) {
    // Backfill only runs while the Hyper 2 cache table still exists.
    LegacyElementCacheSeeder::ensureLegacyTable();
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField();
    $section = F::translatableEntrySection($field, 2);
    $target = F::plainEntry($section, 'Target', [], $primary);
    $owner = F::plainEntry($section, 'Canonical owner', [], $primary);
    $creatorId = User::find()->admin()->one()->id;
    $derivativeId = $kind === 'revision'
        ? Craft::$app->revisions->createRevision($owner, $creatorId, force: true)
        : Craft::$app->drafts->createDraft($owner, $creatorId, 'Upgrade draft', provisional: $kind === 'provisional')->id;
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;

    // Seed Hyper 2 content without passing it through Hyper 3 serialization.
    $payload = [$uid => [[
        'type' => verbb\hyper\links\Entry::class,
        'handle' => 'entry',
        'linkValue' => [$target->id],
        'linkText' => 'Preserved legacy link',
    ]]];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', [
        'content' => new yii\db\JsonExpression($payload),
    ], ['elementId' => [$owner->id, $derivativeId]])->execute();
    LinkRelation::deleteAll(['fieldId' => $field->id]);
    $readContent = fn() => (new Query())->select(['elementId', 'siteId', 'content'])
        ->from('{{%elements_sites}}')->where(['elementId' => [$owner->id, $derivativeId]])->orderBy('id')->all();
    $readRelations = fn() => (new Query())->select(['ownerId', 'ownerSiteId', 'targetId'])
        ->from('{{%hyper_links}}')->where(['fieldId' => $field->id])->orderBy('ownerSiteId')->all();
    $before = $readContent();
    expect($before)->toHaveCount(4);

    foreach ([$primary, $secondary] as $site) {
        // getElementById already removes Craft's default draft/revision filters.
        $derivative = Craft::$app->elements->getElementById($derivativeId, Entry::class, $site->id, ['trashed' => null]);
        expect($derivative)->not->toBeNull();
        expect($kind === 'revision' ? $derivative->getIsRevision() : $derivative->getIsDraft())->toBeTrue();
        expect((bool)$derivative->isProvisionalDraft)->toBe($kind === 'provisional');
    }

    try {
        if (!$loadable) {
            // Retained derivative content must not require its element-specific
            // records to hydrate before the rebuild can exclude it.
            Craft::$app->db->createCommand()->update('{{%elements}}', [
                'type' => craft\elements\Asset::class,
            ], ['id' => $derivativeId])->execute();
            expect(Craft::$app->elements->getElementById($derivativeId, craft\elements\Asset::class, $primary->id))->toBeNull();
        }

        expect(Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: true)))->toBe(2);
        expect($readRelations())->toBe([]);
        expect($readContent())->toBe($before);
        expect((new $migrationClass())->safeUp())->toBeTrue();
        $relations = $readRelations();
        expect(array_map('intval', array_column($relations, 'ownerId')))->toBe([$owner->id, $owner->id]);
        expect(array_map('intval', array_column($relations, 'ownerSiteId')))->toBe([$primary->id, $secondary->id]);
        expect(array_map('intval', array_column($relations, 'targetId')))->toBe([$target->id, $target->id]);
        expect($readContent())->toBe($before);
        expect(Hyper::$plugin->content->reconcileRelations($field))->toBe(2);
        expect($readRelations())->toBe($relations);
        expect($readContent())->toBe($before);
    } finally {
        Rebuild::$rebuiltByBackfill = false;
        Craft::$app->db->createCommand()->update('{{%elements}}', ['type' => Entry::class], ['id' => $derivativeId])->execute();
    }
})->with(['draft', 'provisional', 'revision'])->with([Backfill::class, Rebuild::class])->with(['loadable' => true, 'unloadable' => false]);
