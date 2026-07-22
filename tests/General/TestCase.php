<?php

declare(strict_types=1);

namespace Tests\General;

use Craft;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureCraftBootstrapped();
    }

    protected function tearDown(): void
    {
        if (class_exists(Craft::class) && Craft::$app?->getIsInstalled()) {
            Craft::$app->getGql()->setActiveSchema(null);

            if (class_exists(\verbb\hyper\Hyper::class) && \verbb\hyper\Hyper::$plugin) {
                \verbb\hyper\Hyper::$plugin->getLinkRelations()->resetRequestState();
            }
        }

        parent::tearDown();
    }

    protected function ensureCraftBootstrapped(): void
    {
        if (!class_exists(Craft::class) || !Craft::$app) {
            throw new RuntimeException('Craft application must be bootstrapped before running integration tests.');
        }
    }
}
