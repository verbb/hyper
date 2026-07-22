<?php

declare(strict_types=1);

use Tests\Support\Performance\PerformanceBaselines;
use Tests\Support\Performance\SaveLinkPerfScenario;
use yii\console\ExitCode;

require dirname(__DIR__) . '/bootstrap-craft.php';

if ((getenv('ENVIRONMENT') ?: '') !== 'testing') {
    fwrite(STDERR, "Refusing baseline capture outside ENVIRONMENT=testing.\n");
    exit(ExitCode::UNSPECIFIED_ERROR);
}

$scenario = SaveLinkPerfScenario::ownerWithEntryLinks(30);
$wired = SaveLinkPerfScenario::profileRelationSync($scenario);
$legacy = SaveLinkPerfScenario::profileLegacyUpsertCache($scenario);

$scenarios = [
    'relation-sync-30-entry-links' => [
        'linkCount' => $scenario['linkCount'],
        'legacyUpsertCache' => PerformanceBaselines::normalizeProfile($legacy),
        'wired' => PerformanceBaselines::normalizeProfile($wired),
    ],
];

$path = PerformanceBaselines::write($scenarios, PerformanceBaselines::SAVE_BASELINE_VERSION);

fwrite(STDOUT, "Captured save performance baselines to {$path}\n");
fwrite(STDOUT, json_encode($scenarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

exit(ExitCode::OK);
