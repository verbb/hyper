<?php

use Tests\Support\LegacyElementCacheSeeder;
use Tests\Support\Performance\NavLinkPerfScenario;
use Tests\Support\Performance\QueryProfiler;
use Tests\Support\Performance\SaveLinkPerfScenario;

it('counts prohibited queries even when they fall outside the diagnostic top five', function() {
    LegacyElementCacheSeeder::ensureLegacyTable();
    $profile = QueryProfiler::profile(function() {
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot'] as $alias) {
            for ($i = 0; $i < 2; $i++) {
                Craft::$app->db->createCommand('SELECT 1 AS ' . $alias)->queryScalar();
            }
        }
        Craft::$app->db->createCommand('SELECT COUNT(*) FROM {{%hyper_element_cache}}')->queryScalar();
        Craft::$app->db->createCommand('SELECT * FROM {{%elements}} WHERE ([[elements]].[[id]]=0) LIMIT 1')->queryAll();
        return 1;
    });
    expect($profile['queries'])->toBe(14);
    expect($profile['topPatterns'])->toHaveCount(5);
    expect(implode(' ', array_keys($profile['topPatterns'])))->not->toContain('hyper_element_cache');
    expect(NavLinkPerfScenario::countElementCacheQueries($profile))->toBe(1);
    expect(SaveLinkPerfScenario::countPerLinkElementQueries($profile))->toBe(1);
});
