<?php

declare(strict_types=1);

namespace Tests\Support\Performance;

use Craft;
use RuntimeException;
use verbb\hyper\Hyper;

class PerformanceBaselines
{
    public const BASELINE_VERSION = 'v2';
    public const WIRED_BASELINE_VERSION = 'v3-wired';
    public const SAVE_BASELINE_VERSION = 'v3-save';

    public static function path(?string $version = null): string
    {
        $version ??= self::BASELINE_VERSION;

        return dirname(__DIR__, 2) . '/Performance/baselines/' . $version . '.json';
    }

    /**
     * @param array<string, array<string, mixed>> $scenarios
     */
    public static function write(array $scenarios, ?string $version = null): string
    {
        $version ??= self::BASELINE_VERSION;
        $path = self::path($version);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed creating baseline directory: {$directory}");
        }

        $payload = [
            'version' => $version,
            'capturedAt' => gmdate('c'),
            'environment' => [
                'php' => PHP_VERSION,
                'craft' => Craft::$app->getVersion(),
                'hyper' => Hyper::$plugin->getVersion(),
            ],
            'scenarios' => $scenarios,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed encoding performance baselines.');
        }

        if (file_put_contents($path, $encoded . PHP_EOL) === false) {
            throw new RuntimeException("Failed writing performance baselines to {$path}");
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(?string $version = null): array
    {
        $path = self::path($version);

        if (!is_file($path)) {
            throw new RuntimeException("Performance baseline file not found: {$path}");
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Performance baseline file is invalid JSON: {$path}");
        }

        return $decoded;
    }

    public static function normalizeProfile(array $profile): array
    {
        return [
            'resultType' => $profile['resultType'] ?? null,
            'resultSize' => $profile['resultSize'] ?? null,
            'durationMs' => $profile['durationMs'] ?? null,
            'queries' => $profile['queries'] ?? null,
            'duplicatePatterns' => $profile['duplicatePatterns'] ?? null,
            'topPatterns' => $profile['topPatterns'] ?? [],
        ];
    }

    /**
     * Fail when query counts regress above committed baselines.
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     */
    public static function assertProfileWithinBaseline(array $actual, array $expected, string $label): void
    {
        $actualSize = $actual['resultSize'] ?? null;
        $expectedSize = $expected['resultSize'] ?? null;

        if ($actualSize !== $expectedSize) {
            throw new RuntimeException(sprintf(
                '%s: resultSize mismatch (%s !== %s baseline)',
                $label,
                var_export($actualSize, true),
                var_export($expectedSize, true),
            ));
        }

        foreach (['queries', 'duplicatePatterns'] as $metric) {
            $actualValue = $actual[$metric] ?? null;
            $expectedValue = $expected[$metric] ?? null;

            if (!is_int($actualValue) || !is_int($expectedValue)) {
                throw new RuntimeException(sprintf(
                    '%s: missing %s metric in profile comparison',
                    $label,
                    $metric,
                ));
            }

            if ($actualValue > $expectedValue) {
                throw new RuntimeException(sprintf(
                    '%s: %s regressed (%d > %d baseline)',
                    $label,
                    $metric,
                    $actualValue,
                    $expectedValue,
                ));
            }
        }
    }
}
