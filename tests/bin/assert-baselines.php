<?php

declare(strict_types=1);

use Tests\Support\Performance\PerformanceBaselineRunner;
use yii\console\ExitCode;

require dirname(__DIR__) . '/bootstrap-craft.php';

if ((getenv('ENVIRONMENT') ?: '') !== 'testing') {
    fwrite(STDERR, "Refusing baseline assertion outside ENVIRONMENT=testing.\n");
    exit(ExitCode::UNSPECIFIED_ERROR);
}

$failures = PerformanceBaselineRunner::assertAll();

if ($failures) {
    fwrite(STDERR, "Performance baseline regression detected:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }

    exit(ExitCode::UNSPECIFIED_ERROR);
}

fwrite(STDOUT, "All performance baselines within committed limits.\n");

exit(ExitCode::OK);
