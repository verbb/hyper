<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\fields\Entries as EntriesField;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

it('parses linkedElements with paths from owner queries', function() {
    $field = HyperFixtureFactory::hyperField();
    $handle = $field->handle;
    $query = Entry::find()->with([
        $handle,
        $handle . '.linkedElements',
        $handle . '.linkedElements.relatedEntry',
        $handle . '.linkedElements.relatedEntry.thumbnail',
        'author',
    ]);

    Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);

    expect($query->with)->toBe(['author']);
    expect(Hyper::$plugin->getLinkRelations()->getLinkedElementWithForField($field->id))
        ->toBe(['relatedEntry', 'relatedEntry.thumbnail']);
});

it('batch-loads fields on linked elements when using with linkedElements paths', function() {
    $relatedSection = HyperFixtureFactory::entrySection(null, HyperFixtureFactory::handle('hyperRelatedPool'));
    $relatedEntries = [];

    for ($i = 1; $i <= 5; $i++) {
        $relatedEntries[] = HyperFixtureFactory::plainEntry($relatedSection, 'Related entry ' . $i);
    }

    $relatedField = HyperFixtureFactory::entriesField(null, $relatedSection);
    $targetSection = HyperFixtureFactory::entrySection(null, HyperFixtureFactory::handle('hyperLinkTargets'));
    HyperFixtureFactory::attachFieldToSection($targetSection, $relatedField);

    $targets = [];

    foreach ($relatedEntries as $relatedEntry) {
        $targets[] = HyperFixtureFactory::plainEntry(
            $targetSection,
            'Target for ' . $relatedEntry->title,
            [$relatedField->handle => [$relatedEntry->id]],
        );
    }

    $hyperField = HyperFixtureFactory::hyperField();
    $ownerSection = HyperFixtureFactory::entrySection($hyperField, HyperFixtureFactory::handle('hyperNavOwners'));

    foreach ($targets as $target) {
        HyperFixtureFactory::entryWithLinkPayloads(
            $ownerSection,
            [HyperFixtureFactory::entryLinkPayload($target)],
            'Owner for ' . $target->title,
        );
    }

    $resolveRelated = static function(Entry $target, EntriesField $field): bool {
        $value = $target->getFieldValue($field->handle);

        if ($value instanceof \craft\elements\ElementCollection) {
            return (bool)$value->first();
        }

        return (bool)$value?->one();
    };

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $withoutWith = QueryProfiler::profile(function() use ($ownerSection, $hyperField, $relatedField, $resolveRelated): int {
        $resolved = 0;

        foreach (Entry::find()->section($ownerSection->handle)->all() as $owner) {
            $links = $owner->getFieldValue($hyperField->handle);

            if (!$links instanceof LinkCollection) {
                continue;
            }

            $link = $links->getLinks()[0];
            $target = $link->getElement();

            if ($target && $resolveRelated($target, $relatedField)) {
                $resolved++;
            }
        }

        return $resolved;
    });

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $withPath = $hyperField->handle . '.linkedElements.' . $relatedField->handle;
    $withWith = QueryProfiler::profile(function() use ($ownerSection, $hyperField, $relatedField, $withPath, $resolveRelated): int {
        $resolved = 0;

        foreach (Entry::find()->section($ownerSection->handle)->with([$withPath])->all() as $owner) {
            $links = $owner->getFieldValue($hyperField->handle);

            if (!$links instanceof LinkCollection) {
                continue;
            }

            $link = $links->getLinks()[0];
            $target = $link->getElement();

            if ($target && $resolveRelated($target, $relatedField)) {
                $resolved++;
            }
        }

        return $resolved;
    });

    expect($withoutWith['resultSize'])->toBe(5);
    expect($withWith['resultSize'])->toBe(5);
    expect($withWith['duplicatePatterns'])->toBeLessThan($withoutWith['duplicatePatterns']);
    expect($withWith['queries'])->toBeLessThan($withoutWith['queries']);
});

it('registers linked element with paths during element query prepare', function() {
    $relatedSection = HyperFixtureFactory::entrySection(null, HyperFixtureFactory::handle('hyperRelatedPool2'));
    $relatedEntry = HyperFixtureFactory::plainEntry($relatedSection, 'Related entry');
    $relatedField = HyperFixtureFactory::entriesField(null, $relatedSection);
    $targetSection = HyperFixtureFactory::entrySection(null, HyperFixtureFactory::handle('hyperLinkTargets2'));
    HyperFixtureFactory::attachFieldToSection($targetSection, $relatedField);
    $target = HyperFixtureFactory::plainEntry(
        $targetSection,
        'Linked target',
        [$relatedField->handle => [$relatedEntry->id]],
    );

    $hyperField = HyperFixtureFactory::hyperField();
    $ownerSection = HyperFixtureFactory::entrySection($hyperField, HyperFixtureFactory::handle('hyperNavOwners2'));
    HyperFixtureFactory::entryWithLinkPayloads(
        $ownerSection,
        [HyperFixtureFactory::entryLinkPayload($target)],
        'Owner',
    );

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $query = Entry::find()
        ->section($ownerSection->handle)
        ->with([$hyperField->handle . '.linkedElements.' . $relatedField->handle]);

    Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);

    expect(Hyper::$plugin->getLinkRelations()->getLinkedElementWithForField($hyperField->id))
        ->toBe([$relatedField->handle]);
});
