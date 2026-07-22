<?php

declare(strict_types=1);

namespace Tests\Support\Performance;

use RuntimeException;

final class PerformanceBaselineRunner
{
    /**
     * @return list<string>
     */
    public static function assertAll(): array
    {
        $failures = [];

        foreach (self::wiredNavFailures() as $failure) {
            $failures[] = $failure;
        }

        foreach (self::saveFailures() as $failure) {
            $failures[] = $failure;
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    public static function wiredNavFailures(): array
    {
        $baseline = PerformanceBaselines::read(PerformanceBaselines::WIRED_BASELINE_VERSION);
        $failures = [];

        $scenarios = [
            'single-owner-30-entry-links' => static fn(): array => NavLinkPerfScenario::singleOwnerWithEntryLinks(30),
            'owners-30-single-entry-links' => static fn(): array => NavLinkPerfScenario::ownersWithSingleEntryLink(30),
            'multisite-3x10-entry-links' => static fn(): array => NavLinkPerfScenario::multisiteOwnersWithSingleEntryLink(3, 10),
        ];

        foreach ($scenarios as $name => $factory) {
            $scenario = $factory();
            $profiles = NavLinkPerfScenario::profileLinkedElementHydration($scenario);
            $expected = $baseline['scenarios'][$name]['wired'] ?? null;

            if (!is_array($expected)) {
                $failures[] = "Missing wired baseline scenario: {$name}";
                continue;
            }

            try {
                PerformanceBaselines::assertProfileWithinBaseline(
                    $profiles['wired'],
                    $expected,
                    "v3-wired/{$name}",
                );
            } catch (RuntimeException $e) {
                $failures[] = $e->getMessage();
            }
        }

        $urlTextScenario = NavLinkPerfScenario::singleOwnerWithEntryLinks(30);
        $urlTextProfile = NavLinkPerfScenario::profileUrlTextRead($urlTextScenario);
        $urlTextExpected = $baseline['scenarios']['url-text-read-30-entry-links']['wired'] ?? null;

        if (!is_array($urlTextExpected)) {
            $failures[] = 'Missing wired baseline scenario: url-text-read-30-entry-links';
        } else {
            try {
                PerformanceBaselines::assertProfileWithinBaseline(
                    $urlTextProfile,
                    $urlTextExpected,
                    'v3-wired/url-text-read-30-entry-links',
                );
            } catch (RuntimeException $e) {
                $failures[] = $e->getMessage();
            }
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    public static function saveFailures(): array
    {
        $baseline = PerformanceBaselines::read(PerformanceBaselines::SAVE_BASELINE_VERSION);
        $failures = [];
        $scenario = SaveLinkPerfScenario::ownerWithEntryLinks(30);
        $wired = SaveLinkPerfScenario::profileRelationSync($scenario);
        $expected = $baseline['scenarios']['relation-sync-30-entry-links']['wired'] ?? null;

        if (!is_array($expected)) {
            return ['Missing save baseline scenario: relation-sync-30-entry-links'];
        }

        try {
            PerformanceBaselines::assertProfileWithinBaseline(
                $wired,
                $expected,
                'v3-save/relation-sync-30-entry-links',
            );
        } catch (RuntimeException $e) {
            $failures[] = $e->getMessage();
        }

        return $failures;
    }
}
