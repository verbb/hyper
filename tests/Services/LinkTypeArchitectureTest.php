<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkInstance;
use verbb\hyper\models\LinkTypeDefinition;

it('round-trips link instance content through v3 serialization', function() {
    $field = HyperFixtureFactory::hyperField();
    $payload = HyperFixtureFactory::urlLinkPayload('https://example.test/about', 'About us');

    $instance = LinkInstance::fromSerialized([
        ...$payload,
        'linkTypeHandle' => 'default-verbb-hyper-links-url',
    ], $field);

    expect($instance->linkTypeHandle)->toBe('default-verbb-hyper-links-url');
    expect($instance->linkValue)->toBe('https://example.test/about');
    expect($instance->toSerialized()['linkTypeHandle'])->toBe('default-verbb-hyper-links-url');
    expect($instance->toSerialized())->not->toHaveKey('type');
});

it('resolves v2 serialized content using handle or type fallback', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target')],
        'Target',
    );

    $fromHandle = LinkInstance::fromSerialized(
        HyperFixtureFactory::entryLinkPayload($target, 'Read more'),
        $field,
    );

    expect($fromHandle->linkTypeHandle)->toBe('entry');

    $fromType = LinkInstance::fromSerialized([
        'type' => verbb\hyper\links\Url::class,
        'linkValue' => 'https://example.test/legacy',
    ], $field);

    expect($fromType->linkTypeHandle)->toBe('url');
});

it('detects meaningful attributes without a link value', function() {
    $field = HyperFixtureFactory::hyperField();
    $instance = LinkInstance::fromSerialized([
        'linkTypeHandle' => 'default-verbb-hyper-links-url',
        'linkText' => 'Menu label only',
    ], $field);

    expect($instance->hasLinkValue())->toBeFalse();
    expect($instance->hasMeaningfulAttributes())->toBeTrue();
});

it('converts link type definitions to settings arrays', function() {
    $definition = LinkTypeDefinition::fromSettingsArray([
        'type' => verbb\hyper\links\Url::class,
        'handle' => 'default-verbb-hyper-links-url',
        'label' => 'URL',
        'enabled' => true,
        'layoutUid' => 'test-layout-uid',
    ]);

    expect($definition->toSettingsArray()['handle'])->toBe('default-verbb-hyper-links-url');
    expect($definition->toSettingsArray()['type'])->toBe(verbb\hyper\links\Url::class);
});

it('builds settings prototypes without content state', function() {
    $field = HyperFixtureFactory::hyperField();
    $prototype = $field->getLinkTypes()[0];

    expect($prototype)->toBeInstanceOf(Url::class);
    expect($prototype->isSettingsPrototype())->toBeTrue();
    expect($prototype->linkValue)->toBeNull();
    expect($prototype->linkText)->toBeNull();
});

it('hydrates content links independently from settings prototypes', function() {
    $field = HyperFixtureFactory::hyperField();
    $prototype = $field->getLinkTypes()[0];

    $content = Hyper::$plugin->getLinks()->createLinkFromInstance($field, LinkInstance::fromSerialized([
        'linkTypeHandle' => $prototype->handle,
        'linkValue' => 'https://example.test/content',
        'linkText' => 'Content link',
    ], $field));

    expect($prototype->linkValue)->toBeNull();
    expect($content?->linkValue)->toBe('https://example.test/content');
    expect($content?->getCustomLinkText())->toBe('Content link');
});

it('returns independent clones from getLinkTypeByHandle()', function() {
    $field = HyperFixtureFactory::hyperField();

    $first = $field->getLinkTypeByHandle($field->defaultLinkType);
    $second = $field->getLinkTypeByHandle($field->defaultLinkType);

    expect($first)->not->toBe($second);
    $first->linkText = 'Mutated';

    expect($second->linkText)->toBeNull();
});

it('reads link type definitions from serialized settings without content bleed', function() {
    $field = HyperFixtureFactory::hyperField();
    $serialized = HyperFixtureFactory::linkTypeConfig(Url::class);
    $serialized['linkValue'] = 'https://example.test/should-not-bleed';
    $serialized['linkText'] = 'Should not bleed';

    $field->setLinkTypes([$serialized]);

    $definition = $field->getLinkTypeDefinitionByHandle($serialized['handle']);

    expect($definition)->not->toBeNull();
    expect($definition->type)->toBe(Url::class);

    $prototype = $field->getLinkTypes()[0];
    expect($prototype->linkValue)->toBeNull();
    expect($prototype->linkText)->toBeNull();
});

it('persists link type configs in project config', function() {
    $configs = Hyper::$plugin->getLinkTypeConfigs();
    $stock = $configs->createStockSerializedLinkTypes();
    $stock = array_slice($stock, 0, 1);
    $stock[0]['label'] = 'Simple URL';
    $stock[0]['sortOrder'] = '0';

    $config = new \verbb\hyper\models\LinkTypeConfig([
        'name' => 'Simple',
        'handle' => 'simple',
        'linkTypes' => $stock,
    ]);

    expect($configs->saveConfig($config))->toBeTrue();

    $loaded = $configs->getConfigByHandle('simple');

    expect($loaded)->not->toBeNull();
    expect($loaded->linkTypes[0]['label'])->toBe('Simple URL');

    $configs->deleteConfig($config->uid);

    expect($configs->getConfigByHandle('simple'))->toBeNull();
});

it('reserves the default handle for the seeded config', function() {
    $config = new \verbb\hyper\models\LinkTypeConfig([
        'linkTypes' => array_slice(
            Hyper::$plugin->getLinkTypeConfigs()->createStockSerializedLinkTypes(),
            0,
            1,
        ),
    ]);

    expect(Hyper::$plugin->getLinkTypeConfigs()->saveConfig($config))->toBeFalse()
        ->and($config->handle)->toBe('')
        ->and($config->getErrors('name'))->not->toBeEmpty()
        ->and($config->getErrors('handle'))->not->toBeEmpty();
});

it('reorders link type configs for the admin table', function() {
    $configs = Hyper::$plugin->getLinkTypeConfigs();
    $stock = array_slice($configs->createStockSerializedLinkTypes(), 0, 1);
    $simple = new \verbb\hyper\models\LinkTypeConfig([
        'name' => 'Simple',
        'handle' => 'simple',
        'linkTypes' => $stock,
    ]);
    $advanced = new \verbb\hyper\models\LinkTypeConfig([
        'name' => 'Advanced',
        'handle' => 'advanced',
        'linkTypes' => $stock,
    ]);

    expect($configs->saveConfig($simple))->toBeTrue();
    expect($configs->saveConfig($advanced))->toBeTrue();

    $default = $configs->getDefaultConfig();
    expect($configs->reorderConfigs([$advanced->uid, $simple->uid, $default->uid]))->toBeTrue();
    expect(array_map(
        static fn($config): string => $config->handle,
        $configs->getAllConfigs(),
    ))->toBe(['advanced', 'simple', 'default']);

    $configs->deleteConfig($simple->uid);
    $configs->deleteConfig($advanced->uid);
});

it('coerces legacy editor modes to blocks view mode', function() {
    expect(HyperField::normalizeViewMode('compact'))->toBe(HyperField::VIEW_MODE_BLOCKS);
    expect(HyperField::normalizeViewMode('inline'))->toBe(HyperField::VIEW_MODE_BLOCKS);
    expect(HyperField::normalizeViewMode('expanded'))->toBe(HyperField::VIEW_MODE_BLOCKS);
    expect(HyperField::normalizeViewMode('cards'))->toBe(HyperField::VIEW_MODE_CARDS);

    $field = new HyperField([
        'name' => 'Legacy mode',
        'handle' => HyperFixtureFactory::handle('hyperLegacyMode'),
        'editorMode' => 'expanded',
    ]);

    expect($field->viewMode)->toBe(HyperField::VIEW_MODE_BLOCKS);
});

it('uses a named link type config or the custom dropdown option', function() {
    $field = new HyperField([
        'name' => 'Attached Hyper',
        'handle' => HyperFixtureFactory::handle('hyperAttached'),
    ]);

    expect($field->linkTypeConfig)->toBe('default');
    expect($field->hasCustomLinkTypes())->toBeFalse();
    expect($field->getLinkTypes())->not->toBeEmpty();

    $field->enableCustomLinkTypes();

    expect($field->linkTypeConfig)->toBe('custom');
    expect($field->hasCustomLinkTypes())->toBeTrue();
    expect($field->getSettings()['linkTypes'])->not->toBeEmpty();

    $field->useLinkTypeConfig('default');

    expect($field->linkTypeConfig)->toBe('default');
    expect($field->hasCustomLinkTypes())->toBeFalse();
    expect($field->getSettings()['linkTypes'])->toBe([]);
});

it('treats existing fields with stored link types as custom without migration', function() {
    $field = new HyperField([
        'name' => 'Legacy Hyper',
        'handle' => HyperFixtureFactory::handle('hyperLegacy'),
        'linkTypes' => [HyperFixtureFactory::linkTypeConfig(Url::class)],
    ]);

    expect($field->linkTypeConfig)->toBe('custom');
    expect($field->hasCustomLinkTypes())->toBeTrue();
});
