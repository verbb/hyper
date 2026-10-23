<?php

use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\services\Links;
use verbb\hyper\migrations\m250703_010000_backfill_hyper_links_from_element_cache as Backfill;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;

it('hydrates only the owner links needed for reconciliation', function(bool $revision) {
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $owner = F::plainEntry($section, 'Owner');
    $ownerId = $revision
        ? Craft::$app->revisions->createRevision($owner, User::find()->admin()->one()->id, force: true)
        : $owner->id;
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', [
        'content' => new yii\db\JsonExpression([
            $uid => array_fill(0, 1000, F::urlLinkPayload('/retained')),
        ]),
    ], ['elementId' => $ownerId])->execute();
    $read = fn() => (new craft\db\Query())->select('content')->from('{{%elements_sites}}')->where(['elementId' => $ownerId])->scalar();
    $before = $read();
    $original = Hyper::$plugin->getLinks();
    $counting = new class extends Links {
        public int $hydrated = 0;

        public function createLinkFromSerialized(HyperField $field, array $data): ?LinkInterface
        {
            $this->hydrated++;
            return parent::createLinkFromSerialized($field, $data);
        }
    };
    Hyper::$plugin->set('links', $counting);

    try {
        expect(Hyper::$plugin->content->reconcileRelations($field, new ModifyOptions(elementIds: [$ownerId])))->toBe($revision ? 0 : 1);
        expect($counting->hydrated)->toBe($revision ? 0 : 1000);
        expect($read())->toBe($before);
    } finally {
        Hyper::$plugin->set('links', $original);
    }
})->with(['canonical' => false, 'revision' => true]);

it('reports the active field and storage phase during upgrades while respecting compact output', function(string $migrationClass, bool $compact) {
    LegacyElementCacheSeeder::ensureLegacyTable();
    $field = F::hyperField();
    $section = F::entrySection($field);
    F::plainEntry($section, 'Owner', [$field->handle => [F::urlLinkPayload('/retained')]]);
    Rebuild::$rebuiltByBackfill = false;
    ob_start();

    try {
        $migration = new $migrationClass(['compact' => $compact]);
        expect($migration->safeUp())->toBeTrue();
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
        Rebuild::$rebuiltByBackfill = false;
    }

    if ($compact) {
        expect($output)->toBe('');
    } else {
        expect($output)->toContain("{$field->handle} (field {$field->id}) from element content ... done")
            ->toContain("{$field->handle} (field {$field->id}) from legacy content tables ... done");
    }
})->with([Backfill::class, Rebuild::class])->with([false, true]);
