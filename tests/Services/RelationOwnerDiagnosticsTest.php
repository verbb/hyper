<?php

use craft\db\Query;
use craft\elements\db\EntryQuery;
use craft\events\CancelableEvent;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\m250703_010000_backfill_hyper_links_from_element_cache as Backfill;
use verbb\hyper\migrations\m260912_000000_rebuild_link_relations_from_content as Rebuild;
use yii\base\Event;

it('reports stored relation owner metadata without exposing content or changing records', function(string $migrationClass, string $state) {
    LegacyElementCacheSeeder::ensureLegacyTable();
    $field = F::hyperField();
    $target = F::plainEntry(F::entrySection(), 'Private target title');
    $payload = F::entryLinkPayload($target, 'Private link label');
    $db = Craft::$app->getDb();
    $parent = null;

    if ($state === 'nested') {
        $fixture = F::matrixFieldWithHyper($field);
        $parent = F::entryWithMatrixHyperLink(
            F::entrySectionWithField($fixture['matrix']),
            $fixture['matrix'],
            $field,
            $fixture['blockEntryType'],
            [$payload],
            'Private parent title',
        );
        $owner = $parent->getFieldValue($fixture['matrix']->handle)->one();
    } else {
        $owner = F::plainEntry(F::entrySection($field), 'Private owner title', [$field->handle => [$payload]]);
    }

    $entryRow = (new Query())->from('{{%entries}}')->where(['id' => $owner->id])->one();
    $readContent = fn() => (new Query())->from('{{%elements_sites}}')->where(['elementId' => $owner->id])->all();
    $readRelations = fn() => (new Query())->from('{{%hyper_links}}')->orderBy('id')->all();
    $beforeContent = $readContent();
    $beforeRelations = $readRelations();

    if ($state === 'missing') {
        $db->createCommand()->delete('{{%entries}}', ['id' => $owner->id])->execute();
    }

    // A query customization can hide an otherwise intact entry. Diagnostics must
    // distinguish that case from an absent entry record without using hydration.
    $hideOwner = static function(CancelableEvent $event) use ($owner): void {
        if ($event->sender->id == $owner->id) {
            $event->isValid = false;
        }
    };
    Event::on(EntryQuery::class, EntryQuery::EVENT_BEFORE_PREPARE, $hideOwner);

    try {
        $exception = null;

        try {
            $db->transaction(fn() => (new $migrationClass())->safeUp());
        } catch (RuntimeException $error) {
            $exception = $error;
        }

        expect($exception)->toBeInstanceOf(RuntimeException::class);
        $message = $exception->getMessage();
        expect($message)->toContain('Unable to resolve Hyper relation owner.', 'Element ID: ' . $owner->id, ' Diagnostics: ')
            ->not->toContain('Private link label', 'Private owner title', 'Private parent title', 'Private target title');
        $diagnostics = Json::decode(explode(' Diagnostics: ', $message, 2)[1]);
        expect($diagnostics['element']['exists'])->toBeTrue()
            ->and((bool)$diagnostics['element']['archived'])->toBeFalse()
            ->and($diagnostics['element']['dateDeleted'])->toBeNull()
            ->and($diagnostics['element']['draftId'])->toBeNull()
            ->and($diagnostics['element']['revisionId'])->toBeNull()
            ->and($diagnostics['entry']['exists'])->toBe($state !== 'missing');

        if ($state !== 'missing') {
            foreach (['sectionId', 'typeId', 'fieldId', 'primaryOwnerId'] as $key) {
                expect($diagnostics['entry'][$key])->toBe($entryRow[$key]);
            }
        }

        if ($parent) {
            expect($diagnostics['primaryOwner']['exists'])->toBeTrue()
                ->and((int)$diagnostics['primaryOwner']['id'])->toBe($parent->id)
                ->and($diagnostics['primaryOwner']['dateDeleted'])->toBeNull()
                ->and($diagnostics['primaryOwner']['draftId'])->toBeNull()
                ->and($diagnostics['primaryOwner']['revisionId'])->toBeNull()
                ->and($diagnostics['primaryOwnerMappingExists'])->toBeTrue();
        }

        expect($readContent())->toBe($beforeContent)
            ->and($readRelations())->toBe($beforeRelations);
    } finally {
        Event::off(EntryQuery::class, EntryQuery::EVENT_BEFORE_PREPARE, $hideOwner);
        Rebuild::$rebuiltByBackfill = false;

        if ($state === 'missing') {
            $db->createCommand()->insert('{{%entries}}', $entryRow)->execute();
        }
    }
})->with([Backfill::class, Rebuild::class])->with(['missing', 'present', 'nested']);

it('retains the original owner error when diagnostic queries fail', function() {
    $field = F::hyperField();
    $owner = F::plainEntry(F::entrySection($field), 'Private owner', [$field->handle => [F::urlLinkPayload('https://private.example')]]);
    $db = Craft::$app->getDb();
    $unavailableDb = $this->getMockBuilder(craft\db\Connection::class)->onlyMethods(['getQueryBuilder'])->getMock();
    $unavailableDb->expects($this->once())->method('getQueryBuilder')->willThrowException(new RuntimeException('Private connection details'));
    $hideOwner = static function(CancelableEvent $event) use ($owner, $unavailableDb): void {
        if ($event->sender->id == $owner->id) {
            $event->isValid = false;
            Craft::$app->set('db', $unavailableDb);
        }
    };
    Event::on(EntryQuery::class, EntryQuery::EVENT_BEFORE_PREPARE, $hideOwner);

    try {
        $exception = null;

        try {
            Hyper::$plugin->getContent()->reconcileRelations($field);
        } catch (RuntimeException $error) {
            $exception = $error;
        }

        expect($exception)->toBeInstanceOf(RuntimeException::class);
        expect($exception->getMessage())->toContain('Unable to resolve Hyper relation owner.', 'Element ID: ' . $owner->id)
            ->not->toContain('Private connection details', 'private.example');
        $diagnostics = Json::decode(explode(' Diagnostics: ', $exception->getMessage(), 2)[1]);
        expect($diagnostics['element']['exists'])->toBeTrue()
            ->and($diagnostics['collectionError'])->toBe(RuntimeException::class);
    } finally {
        Craft::$app->set('db', $db);
        Event::off(EntryQuery::class, EntryQuery::EVENT_BEFORE_PREPARE, $hideOwner);
    }
});
