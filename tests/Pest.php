<?php

declare(strict_types=1);

use Tests\Support\ResetTestDatabase;
use verbb\hyper\Hyper;

uses(Tests\General\TestCase::class)
    ->in('Gql', 'Performance', 'Services');

beforeEach(function(): void {
    ResetTestDatabase::resetHyperData();

    if (Hyper::$plugin) {
        Hyper::$plugin->getLinkRelations()->resetRequestState();
        Hyper::$plugin->getLinkRelations()->enableRequestPriming = true;
    }
})->in('Services', 'Performance');
