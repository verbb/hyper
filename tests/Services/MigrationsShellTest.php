<?php

declare(strict_types=1);

use verbb\hyper\helpers\MigrationRenderer;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\MigrateCraftLinkContent;
use verbb\hyper\migrations\PluginMigration;
use verbb\hyper\migrations\plugins\Line;
use verbb\hyper\migrations\plugins\MigrationResult;
use verbb\hyper\migrations\plugins\PluginMigrator;
use yii\console\Controller;

it('registers migration sources including craft-link', function() {
    $sources = Hyper::$plugin->getMigrations()->getSources();

    expect($sources)->toHaveKeys(['typed-link', 'linkit', 'link', 'craft-link']);
    expect($sources['craft-link']['consoleCommand'])->toBe('hyper/migrate/craft-link');
    expect($sources['typed-link']['steps'])->toHaveCount(3);
    expect($sources['linkit']['steps'])->toHaveCount(2);
});

it('resolves step migration classes from the registry', function() {
    $migrations = Hyper::$plugin->getMigrations();

    expect($migrations->getStepMigrationClass('craft-link', 'content'))
        ->toBe(MigrateCraftLinkContent::class);
    expect($migrations->getStepMigrationClass('typed-link', 'legacy'))
        ->not->toBeNull();
    expect($migrations->getStepMigrationClass('linkit', 'missing'))
        ->toBeNull();
});

it('parses craft element link values in content migrator', function() {
    $migrator = new MigrateCraftLinkContent();
    $method = new ReflectionMethod($migrator, '_parseCraftElementValue');
    $method->setAccessible(true);

    expect($method->invoke($migrator, '{craft\\elements\\Entry:42@1}'))
        ->toBe(['elementId' => 42, 'siteId' => 1]);
    expect($method->invoke($migrator, '99'))
        ->toBe(['elementId' => 99, 'siteId' => null]);
    expect($method->invoke($migrator, 'not-a-link'))->toBeNull();
});

it('renders migration result html and console-safe lines', function() {
    $result = new MigrationResult();
    $result->addLine(Line::info('Starting'));
    $result->addLine(Line::success('Done', 1));
    $result->addLine(Line::warning('Heads up'));
    $result->incrementStat('fieldsMigrated', 2);

    $html = MigrationResult::renderLinesHtml($result->lines);

    expect($html)->toContain('hyper-settings-migrate-log');
    expect($html)->toContain('Starting');
    expect($html)->toContain('Done');
    expect($result->stats['fieldsMigrated'])->toBe(2);

    // Renderer should not throw
    ob_start();
    MigrationRenderer::renderResultToConsole($result);
    ob_end_clean();
});

it('neutralises terminal controls in migration output', function() {
    $hostile = "Printable\x1B[2K\x1B]0;title\x07\r\x08\u{0085} text";
    $sanitized = MigrationRenderer::sanitizeConsoleText($hostile);

    expect(preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $sanitized))->toBe(0)
        ->and($sanitized)->toContain('Printable')
        ->and($sanitized)->toContain('text');

    $result = new MigrationResult();
    $result->addLine(Line::info($hostile));
    $result->setStat("rows\x1B]0;key\x07", "2\x1B[2K");
    ob_start();
    MigrationRenderer::renderResultToConsole($result);
    $rendered = (string)ob_get_clean();

    expect($rendered)->not->toContain("\x1B]0;")
        ->and($rendered)->not->toContain("\x07");

    $controller = new class('migration-test', Hyper::$plugin) extends Controller {
        public string $output = '';

        public function stdout($string): void
        {
            $this->output .= $string;
        }
    };
    $migration = new PluginMigration();
    $collected = new MigrationResult();
    $migration->setConsoleRequest($controller);
    $migration->setMigrationResult($collected);
    $migration->stdout("Direct\x1B]0;title\x07" . PHP_EOL);

    expect($controller->output)->toBe('Direct]0;title' . PHP_EOL . PHP_EOL)
        ->and($collected->lines[0]->message)->toBe('Direct]0;title');
});

it('creates a plugin migrator via the plugin factory', function() {
    $migrator = Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class, [
        'dryRun' => true,
        'syncRelations' => false,
    ]);

    expect($migrator)->toBeInstanceOf(PluginMigrator::class);
    expect($migrator->migrationClass)->toBe(MigrateCraftLinkContent::class);
    expect($migrator->dryRun)->toBeTrue();
});
