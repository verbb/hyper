<?php

declare(strict_types=1);

use Tests\Support\Performance\NavLinkPerfScenario;
use Tests\Support\Performance\PerformanceBaselines;
use yii\console\ExitCode;

require dirname(__DIR__) . '/bootstrap-craft.php';

if ((getenv('ENVIRONMENT') ?: '') !== 'testing') {
    fwrite(STDERR, "Refusing baseline capture outside ENVIRONMENT=testing.\n");
    exit(ExitCode::UNSPECIFIED_ERROR);
}

$scenarios = [];

foreach ([
    'single-owner-30-entry-links' => NavLinkPerfScenario::singleOwnerWithEntryLinks(30),
    'owners-30-single-entry-links' => NavLinkPerfScenario::ownersWithSingleEntryLink(30),
    'multisite-3x10-entry-links' => NavLinkPerfScenario::multisiteOwnersWithSingleEntryLink(3, 10),
] as $name => $scenario) {
    $profiles = NavLinkPerfScenario::profileLinkedElementHydration($scenario);

    $scenarios[$name] = [
        'expectedLinked' => $scenario['expectedLinked'],
        'legacy' => PerformanceBaselines::normalizeProfile($profiles['legacy']),
        'wired' => PerformanceBaselines::normalizeProfile($profiles['wired']),
    ];
}

$urlTextScenario = NavLinkPerfScenario::singleOwnerWithEntryLinks(30);
$scenarios['url-text-read-30-entry-links'] = [
    'expectedLinked' => $urlTextScenario['expectedLinked'],
    'wired' => PerformanceBaselines::normalizeProfile(
        NavLinkPerfScenario::profileUrlTextRead($urlTextScenario),
    ),
];

$path = PerformanceBaselines::write($scenarios, PerformanceBaselines::WIRED_BASELINE_VERSION);

fwrite(STDOUT, "Captured " . count($scenarios) . " wired performance baselines to {$path}\n");
fwrite(STDOUT, json_encode($scenarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

exit(ExitCode::OK);
