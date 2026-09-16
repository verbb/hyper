<?php

declare(strict_types=1);

use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Performance\NavLinkPerfScenario;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('executes GraphQL link hydration with exact results and a bounded cold query count', function() {
    $scenario = NavLinkPerfScenario::singleOwnerWithEntryLinks(20);
    $field = $scenario['field'];
    $type = Craft::$app->entries->getEntryTypesBySectionId($scenario['section']->id)[0];
    $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Hydration budget', 'scope' => ['sections.' . $scenario['section']->uid . ':read']]);
    $original = Craft::$app->gql;
    $original->flushCaches();
    $gql = new \craft\services\Gql();
    Craft::$app->set('gql', $gql);
    try {
        // Schema construction is independent of link count. Measure actual execution with cold links.
        $gql->getSchemaDef($schema);
        Hyper::$plugin->getLinkRelations()->resetRequestState();
        $query = '{ entries(id: ' . $scenario['owners'][0]->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . ' { url text element { id } } } } }';
        $result = null;
        $profile = QueryProfiler::profile(function() use ($gql, $schema, $query, &$result) {
            $result = $gql->executeQuery($schema, $query, debugMode: true);
            return $result;
        });
        expect($result['errors'] ?? [])->toBe([], json_encode($result));
        $expected = array_map(fn($row) => ['url' => $row[4], 'text' => $row[5], 'element' => ['id' => (string)$row[2]]], $scenario['expectedRows']);
        expect($result['data']['entries'][0][$field->handle])->toBe($expected);
        // Includes Craft's lazy metadata reads; twenty per-link lookups cannot fit this budget.
        expect($profile['queries'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10, json_encode($profile));
        expect(NavLinkPerfScenario::countElementCacheQueries($profile))->toBe(0);
    } finally {
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
})->group('perf');
