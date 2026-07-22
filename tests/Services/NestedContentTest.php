<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\content\locators\MatrixNestedFieldLocator;
use verbb\hyper\content\locators\VizyNestedFieldLocator;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\content\NestedFieldPlacementFinder;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

it('locates hyper values inside matrix block JSON', function() {
    $hostValue = [
        101 => [
            'type' => 'cta',
            'fields' => [
                'myLink' => [
                    ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/matrix'],
                ],
            ],
        ],
    ];

    $locator = new MatrixNestedFieldLocator();
    $locations = $locator->locateInHostValue($hostValue, 'myLink', 'matrixLayoutUid');

    expect($locations)->toHaveCount(1);
    expect($locations[0]->jsonPath)->toBe('matrixLayoutUid.101.fields.myLink');

    $locations[0]->value[0]['linkText'] = 'Matrix link';

    expect($hostValue[101]['fields']['myLink'][0]['linkText'])->toBe('Matrix link');
});

it('locates hyper values inside vizy block JSON', function() {
    $hostValue = [
        'html' => '<p>Hello</p>',
        'data' => [
            'blocks' => [
                'block-1' => [
                    'type' => 'cta',
                    'fields' => [
                        'ctaLink' => [
                            ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/vizy'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $locator = new VizyNestedFieldLocator();
    $locations = $locator->locateInHostValue($hostValue, 'ctaLink', 'vizyLayoutUid');

    expect($locations)->toHaveCount(1);
    expect($locations[0]->jsonPath)->toContain('fields.ctaLink');
});

it('finds nested matrix placements for a hyper field', function() {
    $field = HyperFixtureFactory::hyperField();
    $finder = new NestedFieldPlacementFinder();

    expect($finder->findPlacements($field))->toBeArray();
});

it('modifies nested matrix hyper content end to end', function() {
    ['matrix' => $matrixField, 'blockEntryType' => $blockEntryType, 'hyperField' => $hyperField] = HyperFixtureFactory::matrixFieldWithHyper();
    $section = HyperFixtureFactory::entrySectionWithField($matrixField);
    $entry = HyperFixtureFactory::entryWithMatrixHyperLink(
        $section,
        $matrixField,
        $hyperField,
        $blockEntryType,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/nested', 'Nested before')],
    );

    $result = Hyper::$plugin->getContent()->modify(
        $hyperField,
        function(LinkCollection $collection) {
            $links = $collection->getLinks();
            $links[0]->linkText = 'Nested after';

            return $collection->withLinks($links);
        },
        new ModifyOptions(elementIds: [$entry->id]),
    );

    expect($result->matched)->toBeGreaterThanOrEqual(1);
    expect($result->modified)->toBeGreaterThanOrEqual(1);

    $entry = Entry::find()->id($entry->id)->status(null)->one();
    $matrixValue = $entry->getFieldValue($matrixField->handle);
    $blocks = is_iterable($matrixValue) ? iterator_to_array($matrixValue) : [];

    expect($blocks)->not->toBeEmpty();

    $block = reset($blocks);
    $links = $block->getFieldValue($hyperField->handle);

    expect($links->getLinks()[0]->linkText)->toBe('Nested after');
});

it('reads vizy block types from field settings when getBlockTypes is unavailable', function() {
    $locator = new VizyNestedFieldLocator();
    $method = new ReflectionMethod($locator, '_getBlockTypeConfigs');
    $method->setAccessible(true);

    $field = new class(['name' => 'Vizy', 'handle' => 'vizyTest']) extends \craft\fields\PlainText {
        public function getSettings(): array
        {
            return [
                'blockTypes' => [
                    ['handle' => 'cta'],
                ],
            ];
        }
    };

    expect($method->invoke($locator, $field))->toHaveCount(1);
});

it('prefilters content scans with contentContains', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $needle = 'https://example.test/prefilter-' . uniqid();
    $entry = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload($needle, 'Prefilter test')],
    );

    $matching = Hyper::$plugin->getContent()->modify(
        $field,
        static fn(LinkCollection $collection) => $collection,
        new ModifyOptions(dryRun: true, elementIds: [$entry->id], contentContains: $needle),
    );

    $missing = Hyper::$plugin->getContent()->modify(
        $field,
        static fn(LinkCollection $collection) => $collection,
        new ModifyOptions(dryRun: true, elementIds: [$entry->id], contentContains: '00000000-0000-0000-0000-000000000000'),
    );

    expect($matching->matched)->toBeGreaterThanOrEqual(1);
    expect($missing->matched)->toBe(0);
});
