<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\PerformanceBaselines;
use Tests\Support\Performance\QueryProfiler;
use yii\console\ExitCode;

require dirname(__DIR__) . '/bootstrap-craft.php';

if ((getenv('ENVIRONMENT') ?: '') !== 'testing') {
    fwrite(STDERR, "Refusing baseline capture outside ENVIRONMENT=testing.\n");
    exit(ExitCode::UNSPECIFIED_ERROR);
}

$scenarios = [];

$field = HyperFixtureFactory::hyperField();
$section = HyperFixtureFactory::entrySection($field);
HyperFixtureFactory::entries(10, $section);

$scenarios['read-hyper-link-fields'] = PerformanceBaselines::normalizeProfile(
    QueryProfiler::profile(function() use ($section, $field): int {
        $entries = Entry::find()
            ->section($section->handle)
            ->all();

        $resolved = 0;

        foreach ($entries as $entry) {
            $links = $entry->getFieldValue($field->handle);

            if ($links?->getUrl()) {
                $resolved++;
            }
        }

        return $resolved;
    }),
);

$field = HyperFixtureFactory::hyperField();
$section = HyperFixtureFactory::entrySection($field);
$targets = HyperFixtureFactory::entries(5, $section);

foreach ($targets as $target) {
    HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, $target->title)],
        'Nav item for ' . $target->title,
    );
}

$scenarios['read-urls-only'] = PerformanceBaselines::normalizeProfile(
    QueryProfiler::profile(function() use ($section, $field): int {
        $count = 0;

        foreach (Entry::find()->section($section->handle)->all() as $entry) {
            if ($entry->getFieldValue($field->handle)?->getUrl()) {
                $count++;
            }
        }

        return $count;
    }),
);

$scenarios['touch-linked-elements'] = PerformanceBaselines::normalizeProfile(
    QueryProfiler::profile(function() use ($section, $field): int {
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
    }),
);

$path = PerformanceBaselines::write($scenarios);

fwrite(STDOUT, "Captured " . count($scenarios) . " performance baselines to {$path}\n");
fwrite(STDOUT, json_encode($scenarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

exit(ExitCode::OK);
