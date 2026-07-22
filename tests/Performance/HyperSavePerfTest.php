<?php

declare(strict_types=1);

use Tests\Support\Performance\SaveLinkPerfScenario;

it('syncs 30 entry links without per-link getElement N+1', function() {
    $scenario = SaveLinkPerfScenario::ownerWithEntryLinks(30);
    $wired = SaveLinkPerfScenario::profileRelationSync($scenario);
    $legacy = SaveLinkPerfScenario::profileLegacyUpsertCache($scenario);

    expect($wired['resultSize'])->toBe(30);
    expect($legacy['resultSize'])->toBe(30);
    expect($wired['duplicatePatterns'])->toBeLessThan($legacy['duplicatePatterns']);
    expect($wired['queries'])->toBeLessThan($legacy['queries']);
    expect(SaveLinkPerfScenario::countPerLinkElementQueries($wired))->toBe(0);
    expect(SaveLinkPerfScenario::countPerLinkElementQueries($legacy))->toBeGreaterThan(20);

    fwrite(STDERR, "\n30-link relation sync vs legacy upsertCache:\n" . json_encode([
        'wired' => $wired,
        'legacyUpsertCache' => $legacy,
    ], JSON_PRETTY_PRINT) . "\n");
})->group('perf');
