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
use Tests\Support\CpActionRequest;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\content\Change;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry;
use verbb\hyper\migrations\m250703_010000_backfill_hyper_links_from_element_cache as Backfill;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;
use verbb\hyper\models\LinkCollection;
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
        // Craft's GC deletes blocks without a Neo record, so they are skipped like core partial elements.
        $partial = $kind === 'canonical' && $state === 'missing-neo-row';
        $shouldFail = $kind === 'canonical' && $broken && !$partial;
        $dryRun = fn() => Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: true));
        if ($shouldFail) {
            expect($dryRun)->toThrow(RuntimeException::class, 'Unable to resolve Hyper relation owner.');
        } else {
            expect($dryRun())->toBe($partial ? 0 : 1);
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
                if ($partial) {
                    expect($relations)->toBe([]);
                    continue;
                }
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

it('skips Neo blocks left without Neo records by applied drafts when rebuilding relations', function() {
    expect(Craft::$app->plugins->isPluginEnabled('neo'))->toBeTrue();

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
    $partialId = null;

    try {
        expect(Craft::$app->fields->saveField($neo))->toBeTrue();
        // Neo memoizes the unsaved block type while the field is created.
        unset(Memoize::$blockTypesByFieldId[$neo->id]);
        $neo = Craft::$app->fields->getFieldById($neo->id);
        $section = F::entrySectionWithField($neo);
        $target = F::plainEntry($section, 'Target');
        $blockValue = fn(string $text) => [
            'type' => 'links',
            'enabled' => true,
            'level' => 1,
            'fields' => [$field->handle => [F::entryLinkPayload($target, $text)]],
        ];
        $owner = F::plainEntry($section, 'Neo owner', [$neo->handle => ['new1' => $blockValue('Original')]]);
        $canonical = $owner->getFieldValue($neo->handle)->one();
        expect($canonical)->toBeInstanceOf(Block::class);

        // Editing a Neo block through a draft and applying it, as a CP save does, leaves the
        // draft's block element and content behind without a neoblocks row until Craft's GC.
        $admin = User::find()->admin()->one();
        CpActionRequest::asUser($admin, function() use ($owner, $admin, $neo, $canonical, $blockValue): void {
            // Post the canonical block in Neo's CP format, which duplicates it into the draft.
            $draft = Craft::$app->drafts->createDraft($owner, $admin->id, 'Neo draft');
            $draft->setFieldValue($neo->handle, [
                'blocks' => [$canonical->id => $blockValue('Edited')],
                'sortOrder' => [$canonical->id],
            ]);
            expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
            Craft::$app->drafts->applyDraft(\craft\elements\Entry::find()->id($draft->id)->drafts(true)->status(null)->one());
        });
        $applied = \craft\elements\Entry::find()->id($owner->id)->one()->getFieldValue($neo->handle)->one();
        expect($applied?->id)->toBe($canonical->id);

        $partialIds = (new Query())->select('elements.id')->from(['elements' => '{{%elements}}'])
            ->leftJoin(['neoblocks' => '{{%neoblocks}}'], '[[neoblocks.id]] = [[elements.id]]')
            ->where(['elements.type' => Block::class, 'neoblocks.id' => null])
            ->andWhere(['elements.dateDeleted' => null, 'elements.draftId' => null, 'elements.revisionId' => null])
            ->column();
        expect($partialIds)->toHaveCount(1);
        $partialId = (int)$partialIds[0];
        expect($partialId)->not->toBe($canonical->id)
            ->and(Craft::$app->elements->getElementById($partialId, Block::class, $owner->siteId, ['trashed' => null]))->toBeNull();

        $readContent = fn() => (new Query())->select(['elementId', 'siteId', 'content'])
            ->from('{{%elements_sites}}')->where(['elementId' => [$canonical->id, $partialId]])->orderBy('id')->all();
        $readRelations = fn() => (new Query())->select(['ownerId', 'ownerSiteId', 'targetId'])
            ->from('{{%hyper_links}}')->where(['fieldId' => $field->id])->all();
        $expectRelations = function() use ($readRelations, $canonical, $owner, $target): void {
            $relations = $readRelations();
            expect($relations)->toHaveCount(1)
                ->and((int)$relations[0]['ownerId'])->toBe($canonical->id)
                ->and((int)$relations[0]['ownerSiteId'])->toBe($owner->siteId)
                ->and((int)$relations[0]['targetId'])->toBe($target->id);
        };
        $beforeContent = $readContent();
        expect($beforeContent)->toHaveCount(2)
            ->and(array_filter(array_column($beforeContent, 'content')))->toHaveCount(2);
        $expectRelations();

        // A stale relation proves each rebuild actually runs from the stored JSON.
        $stale = fn() => expect($db->createCommand()->update('{{%hyper_links}}', ['targetId' => $owner->id], ['fieldId' => $field->id])->execute())->toBe(1);

        // sync-relations, content steps and the m260912 upgrade all rebuild through reconcileRelations().
        $stale();
        $relationsBefore = $readRelations();
        expect(Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(dryRun: true)))->toBe(1)
            ->and($readRelations())->toBe($relationsBefore);
        expect(Hyper::$plugin->content->reconcileRelations($field))->toBe(1);
        $expectRelations();

        $stale();
        Rebuild::$rebuiltByBackfill = false;
        expect($db->transaction(fn() => (new Rebuild())->safeUp()))->toBeTrue();
        $expectRelations();
        expect($readContent())->toBe($beforeContent);

        // Raw writes to the canonical block rebuild the whole field's index afterwards.
        $stale();
        $result = Hyper::$plugin->content->modifyRaw($field, function(mixed $raw, $ref) use ($canonical) {
            if ($ref->elementId !== $canonical->id || !is_array($raw)) {
                return Change::unchanged();
            }

            $raw[0]['linkText'] = 'Raw edit';

            return Change::replace($raw);
        });
        expect($result->modified)->toBe(1);
        $expectRelations();
        expect($canonical->id)->toBe((int)$readContent()[0]['elementId'])
            ->and($readContent()[0]['content'])->toContain('Raw edit')
            ->and($readContent()[1])->toBe($beforeContent[1]);

        // Content edits that target the partial block still report it, as for core partial elements.
        expect(fn() => Hyper::$plugin->content->modify($field, fn() => new LinkCollection($field, []), new ModifyOptions(elementIds: [$partialId])))
            ->toThrow(RuntimeException::class, 'Unable to resolve Hyper relation owner.');
        expect($readContent()[1])->toBe($beforeContent[1])
            ->and((new Query())->from('{{%elements}}')->where(['id' => $partialId])->exists())->toBeTrue();
    } finally {
        Rebuild::$rebuiltByBackfill = false;
        if ($partialId) {
            // GC would remove this element; the field deletion below does not.
            $db->createCommand()->delete('{{%elements}}', ['id' => $partialId])->execute();
        }
        if ($neo?->id) {
            Craft::$app->fields->deleteField($neo);
            unset(Memoize::$blockTypesByFieldId[$neo->id]);
        }
    }
});
