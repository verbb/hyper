<?php

use benf\neo\elements\Block;
use benf\neo\Field;
use benf\neo\helpers\Memoize;
use benf\neo\models\BlockType;
use craft\db\Query;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry;
use verbb\hyper\migrations\m250703_010000_backfill_hyper_links_from_element_cache as Backfill;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;
use yii\db\JsonExpression;

it('preserves Neo revision content and rejects unresolved canonical owners during upgrades', function(string $kind, string $state, string $migrationClass) {
    expect(Craft::$app->plugins->isPluginInstalled('neo'))->toBeTrue()
        ->and(Craft::$app->plugins->isPluginEnabled('neo'))->toBeTrue();

    // Backfill is conditional on the old table. Create it before any transaction (MySQL DDL).
    LegacyElementCacheSeeder::ensureLegacyTable();
    $db = Craft::$app->db;
    $field = F::hyperField();
    $layout = new FieldLayout(['type' => Block::class]);
    $layout->setTabs([new FieldLayoutTab([
        'name' => 'Content',
        'layout' => $layout,
        'elements' => [new CustomField($field)],
    ])]);
    $type = new BlockType(['name' => 'Links', 'handle' => 'links', 'sortOrder' => 1]);
    $type->setFieldLayout($layout);
    $neo = new Field(['name' => 'Neo', 'handle' => F::handle('hyperTestNeo')]);
    $neo->setBlockTypes([$type]);
    $damageTransaction = null;

    try {
        expect(Craft::$app->fields->saveField($neo))->toBeTrue();
        // Neo memoizes the unsaved block type while the field is created.
        unset(Memoize::$blockTypesByFieldId[$neo->id]);
        $neo = Craft::$app->fields->getFieldById($neo->id);
        $section = F::entrySectionWithField($neo);
        $target = F::plainEntry($section, 'Target');
        $owner = F::plainEntry($section, 'Neo owner', [$neo->handle => ['new1' => [
            'type' => 'links',
            'enabled' => true,
            'level' => 1,
            'fields' => [$field->handle => [F::entryLinkPayload($target, 'Target')]],
        ]]]);
        $canonical = $owner->getFieldValue($neo->handle)->one();
        expect($canonical)->toBeInstanceOf(Block::class);
        Craft::$app->revisions->createRevision($owner, User::find()->admin()->one()->id, force: true);
        $revisionIds = (new Query())->select('id')->from('{{%elements}}')
            ->where(['type' => Block::class, 'canonicalId' => $canonical->id])
            ->andWhere(['not', ['revisionId' => null]])->column();
        expect($revisionIds)->toHaveCount(1);
        $revisionId = (int)$revisionIds[0];
        $blockId = $kind === 'revision' ? $revisionId : $canonical->id;

        // Replay Hyper 2 JSON without normalizing it through Hyper 3's writer.
        $uid = $canonical->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
        $db->createCommand()->update('{{%elements_sites}}', ['content' => new JsonExpression([
            $uid => [[
                'type' => Entry::class,
                'handle' => 'entry',
                'linkValue' => [$target->id],
                'linkText' => 'Preserved legacy Neo link',
            ]],
        ])], ['elementId' => [$canonical->id, $revisionId]])->execute();
        $readContent = fn() => (new Query())->select(['elementId', 'siteId', 'content'])
            ->from('{{%elements_sites}}')->where(['elementId' => [$canonical->id, $revisionId]])->orderBy('id')->all();
        $readRelations = fn() => (new Query())->from('{{%hyper_links}}')->orderBy('id')->all();
        $readOwners = fn() => (new Query())->from('{{%elements_owners}}')->where(['elementId' => $blockId])->orderBy('ownerId')->all();
        $readNeo = fn() => (new Query())->from('{{%neoblocks}}')->where(['id' => $blockId])->one();
        $beforeContent = $readContent();
        expect($beforeContent)->toHaveCount(2);

        $damageTransaction = $db->beginTransaction();
        $neoRow = $readNeo();
        $ownership = $readOwners();
        expect($neoRow)->toBeArray()->and($ownership)->not->toBeEmpty();
        if (in_array($state, ['no-owners', 'alternate-owner-only'], true)) {
            $db->createCommand()->delete('{{%elements_owners}}', ['elementId' => $blockId])->execute();
        }
        if (in_array($state, ['additional-owner', 'alternate-owner-only'], true)) {
            $row = $ownership[0];
            $row['ownerId'] = $target->id;
            $db->createCommand()->insert('{{%elements_owners}}', $row)->execute();
        }
        if ($state === 'stale-primary-owner') {
            // Keep a valid parent element and the old ownership pair, but make them disagree.
            $db->createCommand()->update('{{%neoblocks}}', ['primaryOwnerId' => $target->id], ['id' => $blockId])->execute();
        }
        if ($state === 'missing-neo-row') {
            $db->createCommand()->delete('{{%neoblocks}}', ['id' => $blockId])->execute();
        }
        if ($state === 'deleted-primary-owner') {
            // Neo 5.5.13 leaves partial revision elements after Craft deletes their parent.
            // Exercise that lifecycle without manually removing rows or disabling foreign keys.
            $parent = Craft::$app->elements->getElementById((int)$neoRow['primaryOwnerId']);
            expect($parent)->not->toBeNull()->and($parent->getIsRevision())->toBeTrue();
            expect(Craft::$app->elements->deleteElement($parent, true))->toBeTrue();
            expect($readNeo())->toBeNull()
                ->and((new Query())->from('{{%elements}}')->where(['id' => $blockId])->exists())->toBeTrue();
        }

        $broken = !in_array($state, ['healthy', 'additional-owner'], true);
        $loaded = Craft::$app->elements->getElementById($blockId, Block::class, $owner->siteId, ['trashed' => null]);
        expect($loaded !== null)->toBe(!$broken);
        if ($state === 'alternate-owner-only') {
            // An explicit owner can still load this block; a default by-ID lookup cannot.
            expect(Block::find()->id($blockId)->ownerId($target->id)->siteId($owner->siteId)
                ->status(null)->drafts(null)->revisions(null)->one())->toBeInstanceOf(Block::class);
        }

        // A stale relation proves successful migrations actually rebuild from the stored JSON.
        expect($db->createCommand()->update('{{%hyper_links}}', ['targetId' => $owner->id], ['fieldId' => $field->id])->execute())->toBe(1);
        $beforeRelations = $readRelations();
        $beforeOwners = $readOwners();
        $beforeNeo = $readNeo();
        $shouldFail = $kind === 'canonical' && $broken;
        $dryRun = fn() => Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: true));
        if ($shouldFail) {
            expect($dryRun)->toThrow(RuntimeException::class, 'Unable to resolve Hyper relation owner.');
        } else {
            expect($dryRun())->toBe(1);
        }
        expect($readRelations())->toBe($beforeRelations)
            ->and($readContent())->toBe($beforeContent);

        $migrate = function() use ($db, $migrationClass): void {
            // Craft wraps safeUp() in a transaction. Preserve that rollback boundary here.
            $transaction = $db->beginTransaction();
            Rebuild::$rebuiltByBackfill = false;
            try {
                expect((new $migrationClass())->safeUp())->toBeTrue();
                $transaction->commit();
            } catch (Throwable $error) {
                $transaction->rollBack();
                throw $error;
            } finally {
                // A second invocation must execute, not take the same-process backfill shortcut.
                Rebuild::$rebuiltByBackfill = false;
            }
        };
        if ($shouldFail) {
            expect($migrate)->toThrow(RuntimeException::class, 'Unable to resolve Hyper relation owner.');
            expect($readRelations())->toBe($beforeRelations);
        } else {
            for ($pass = 0; $pass < 2; $pass++) {
                $migrate();
                $relations = (new Query())->select(['ownerId', 'ownerSiteId', 'targetId'])
                    ->from('{{%hyper_links}}')->where(['fieldId' => $field->id])->all();
                expect($relations)->toHaveCount(1)
                    ->and((int)$relations[0]['ownerId'])->toBe($canonical->id)
                    ->and((int)$relations[0]['ownerSiteId'])->toBe($owner->siteId)
                    ->and((int)$relations[0]['targetId'])->toBe($target->id);
            }
        }
        expect($readContent())->toBe($beforeContent)
            ->and($readOwners())->toBe($beforeOwners)
            ->and($readNeo())->toBe($beforeNeo);
    } finally {
        Rebuild::$rebuiltByBackfill = false;
        $damageTransaction?->rollBack();
        if ($neo?->id) {
            Craft::$app->fields->deleteField($neo);
            unset(Memoize::$blockTypesByFieldId[$neo->id]);
        }
    }
})->with([
    'healthy revision' => ['revision', 'healthy'],
    'revision with additional owner' => ['revision', 'additional-owner'],
    'revision without owners' => ['revision', 'no-owners'],
    'revision with alternate owner only' => ['revision', 'alternate-owner-only'],
    'revision with stale primary owner' => ['revision', 'stale-primary-owner'],
    'revision without Neo row' => ['revision', 'missing-neo-row'],
    'revision after parent deletion' => ['revision', 'deleted-primary-owner'],
    'healthy canonical' => ['canonical', 'healthy'],
    'canonical with additional owner' => ['canonical', 'additional-owner'],
    'canonical without owners' => ['canonical', 'no-owners'],
    'canonical with alternate owner only' => ['canonical', 'alternate-owner-only'],
    'canonical with stale primary owner' => ['canonical', 'stale-primary-owner'],
    'canonical without Neo row' => ['canonical', 'missing-neo-row'],
])->with(['backfill' => Backfill::class, 'rebuild' => Rebuild::class]);
