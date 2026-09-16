<?php

require dirname(__DIR__) . '/runtime/bootstrap.php';
// Keep the guarded disposable app, but exercise normal console plugin boot.
putenv('ENVIRONMENT=production');
$_ENV['ENVIRONMENT'] = $_SERVER['ENVIRONMENT'] = 'production';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require_once __DIR__ . '/Performance/QueryProfiler.php';

use craft\elements\Entry;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

$source = (new ReflectionClass(Hyper::class))->getFileName();
if (!str_starts_with(realpath($source), realpath(dirname(__DIR__, 2) . '/src') . '/') || !$app->request->getIsConsoleRequest()) {
    throw new RuntimeException('Unexpected console test source or request context.');
}
$fixture = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$related = Craft::$app->fields->getFieldById($fixture['relatedFieldId']);
$field = Craft::$app->fields->getFieldById($fixture['hyperFieldId']);
$ownerIds = $fixture['ownerIds'];
$targetIds = $fixture['targetIds'];
$results = [];
foreach (['none' => null, 'plain' => $field->handle . '.linkedElements', 'nested' => $field->handle . '.linkedElements.' . $related->handle] as $name => $path) {
    Hyper::$plugin->linkRelations->resetRequestState();
    $owners = Entry::find()->id($ownerIds)->fixedOrder()->with($path ? [$path] : [])->all();
    $targets = [];
    $profile = QueryProfiler::profile(function() use ($owners, $field, &$targets) {
        foreach ($owners as $owner) {
            $targets[] = $owner->getFieldValue($field->handle)->first()->getElement();
        }
        return $targets;
    });
    $results[$name] = [
        'targetQueries' => $profile['queries'],
        'correctTargets' => array_map(fn($target) => $target?->id, $targets) === $targetIds,
        'eagerRelated' => array_map(fn($target) => $target?->hasEagerLoadedElements($related->handle), $targets),
        'relatedIds' => array_map(fn($target) => $target?->getFieldValue($related->handle)->one()?->id, $targets),
    ];
}
echo json_encode(['results' => $results, 'expectedRelated' => array_fill(0, count($ownerIds), $fixture['childId'])], JSON_THROW_ON_ERROR);
