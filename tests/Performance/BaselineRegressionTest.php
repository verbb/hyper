<?php

declare(strict_types=1);

use Tests\Support\Performance\PerformanceBaselineRunner;

it('keeps wired nav hydration within committed baselines', function() {
    $failures = PerformanceBaselineRunner::wiredNavFailures();
    expect($failures)->toBeEmpty(implode("\n", $failures));
})->group('baseline');

it('keeps relation sync save path within committed baselines', function() {
    $failures = PerformanceBaselineRunner::saveFailures();
    expect($failures)->toBeEmpty(implode("\n", $failures));
})->group('baseline');
