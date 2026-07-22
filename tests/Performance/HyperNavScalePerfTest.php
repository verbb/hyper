<?php

declare(strict_types=1);

use Tests\Support\Performance\NavLinkPerfScenario;

it('hydrates 30 entry links on a single owner with fewer queries when wired', function() {
    $scenario = NavLinkPerfScenario::singleOwnerWithEntryLinks(30);
    $profiles = NavLinkPerfScenario::profileLinkedElementHydration($scenario);

    expect($profiles['wired']['resultSize'])->toBe($scenario['expectedLinked']);
    expect($profiles['wired']['duplicatePatterns'])->toBeLessThan($profiles['legacy']['duplicatePatterns']);
    expect($profiles['wired']['queries'])->toBeLessThanOrEqual($profiles['legacy']['queries']);

    fwrite(STDERR, "\n30-link single-owner hydration:\n" . json_encode($profiles, JSON_PRETTY_PRINT) . "\n");
})->group('perf');

it('hydrates 30 single-link owners with fewer queries when wired', function() {
    $scenario = NavLinkPerfScenario::ownersWithSingleEntryLink(30);
    $profiles = NavLinkPerfScenario::profileLinkedElementHydration($scenario);

    expect($profiles['wired']['resultSize'])->toBe($scenario['expectedLinked']);
    expect($profiles['wired']['duplicatePatterns'])->toBeLessThan($profiles['legacy']['duplicatePatterns']);
    expect($profiles['wired']['queries'])->toBeLessThanOrEqual($profiles['legacy']['queries']);

    fwrite(STDERR, "\n30-owner hydration:\n" . json_encode($profiles, JSON_PRETTY_PRINT) . "\n");
})->group('perf');

it('hydrates multisite nav links with fewer queries when wired', function() {
    $scenario = NavLinkPerfScenario::multisiteOwnersWithSingleEntryLink(3, 10);
    $profiles = NavLinkPerfScenario::profileLinkedElementHydration($scenario);

    expect($profiles['wired']['resultSize'])->toBe($scenario['expectedLinked']);
    expect($profiles['wired']['duplicatePatterns'])->toBeLessThan($profiles['legacy']['duplicatePatterns']);
    expect($profiles['wired']['queries'])->toBeLessThanOrEqual($profiles['legacy']['queries']);

    fwrite(STDERR, "\nMultisite hydration:\n" . json_encode($profiles, JSON_PRETTY_PRINT) . "\n");
})->group('perf');

it('reads 30 entry link urls and text via priming without element cache queries', function() {
    $scenario = NavLinkPerfScenario::singleOwnerWithEntryLinks(30);
    $profile = NavLinkPerfScenario::profileUrlTextRead($scenario);

    expect($profile['resultSize'])->toBe($scenario['expectedLinked']);
    expect(NavLinkPerfScenario::countElementCacheQueries($profile))->toBe(0);
    expect($profile['duplicatePatterns'])->toBe(0);
    expect($profile['queries'])->toBeLessThanOrEqual(3);

    fwrite(STDERR, "\n30-link URL/text read:\n" . json_encode($profile, JSON_PRETTY_PRINT) . "\n");
})->group('perf');
