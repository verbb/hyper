<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\PerformanceBaselines;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\base\ElementLink;
use verbb\hyper\Hyper;

it('batch-hydrates linked elements with fewer queries than per-link getElement', function() {
    $field = HyperFixtureFactory::hyperField(['multipleLinks' => true]);
    $section = HyperFixtureFactory::entrySection($field);
    $targets = HyperFixtureFactory::entries(5, $section);

    $owners = [];

    foreach ($targets as $target) {
        $owners[] = HyperFixtureFactory::entryWithLinks(
            $section,
            [HyperFixtureFactory::entryLinkPayload($target, $target->title)],
            'Nav item for ' . $target->title,
        );
    }

    foreach ($owners as $owner) {
        Hyper::$plugin->getLinkRelations()->syncFromLinkCollection(
            $field,
            $owner,
            $owner->getFieldValue($field->handle),
        );
    }

    $ownerDescriptors = array_map(static fn(Entry $owner) => [
        'ownerId' => $owner->id,
        'ownerSiteId' => $owner->siteId,
        'fieldId' => $field->id,
    ], $owners);

    $relations = Hyper::$plugin->getLinkRelations();
    $relations->resetRequestState();
    $relations->enableRequestPriming = false;

    $v2Profile = QueryProfiler::profile(function() use ($section, $field): int {
        $linked = 0;

        foreach (Entry::find()->section($section->handle)->all() as $entry) {
            $links = $entry->getFieldValue($field->handle);

            foreach ($links ?? [] as $link) {
                if (method_exists($link, 'getElement') && $link->getElement()) {
                    $linked++;
                }
            }
        }

        return $linked;
    });

    $relations->clearPrimedElements();
    $relations->enableRequestPriming = true;

    $v3Profile = QueryProfiler::profile(function() use ($section, $field, $ownerDescriptors, $relations): int {
        $entries = Entry::find()->section($section->handle)->all();
        $relations->primeElementsForOwners($ownerDescriptors);

        $linked = 0;

        foreach ($entries as $entry) {
            $links = $entry->getFieldValue($field->handle);

            foreach ($links ?? [] as $link) {
                if (!$link instanceof ElementLink) {
                    continue;
                }

                $linkValue = $link->linkValue;
                $targetId = is_array($linkValue)
                    ? (int)($linkValue[0] ?? 0)
                    : (int)$linkValue;

                if ($targetId && $relations->getPrimedElement($targetId, $link->linkSiteId ?: $entry->siteId)) {
                    $linked++;
                }
            }
        }

        $relations->clearPrimedElements();

        return $linked;
    });

    expect($v3Profile['resultSize'])->toBe(5);
    expect($v3Profile['queries'])->toBeLessThan($v2Profile['queries']);
    expect($v3Profile['duplicatePatterns'])->toBeLessThan($v2Profile['duplicatePatterns']);

    $baseline = PerformanceBaselines::read();
    $v2Baseline = $baseline['scenarios']['touch-linked-elements'] ?? null;

    if ($v2Baseline) {
        expect($v3Profile['queries'])->toBeLessThan((int)$v2Baseline['queries']);
        expect($v3Profile['duplicatePatterns'])->toBeLessThan((int)$v2Baseline['duplicatePatterns']);
    }

    fwrite(STDERR, "\nPhase 1 hydration proof:\n" . json_encode([
        'v2Baseline' => $v2Baseline,
        'v2Measured' => [
            'queries' => $v2Profile['queries'],
            'duplicatePatterns' => $v2Profile['duplicatePatterns'],
            'durationMs' => $v2Profile['durationMs'],
        ],
        'v3Prototype' => [
            'queries' => $v3Profile['queries'],
            'duplicatePatterns' => $v3Profile['duplicatePatterns'],
            'durationMs' => $v3Profile['durationMs'],
        ],
    ], JSON_PRETTY_PRINT) . "\n");
})->group('perf');
