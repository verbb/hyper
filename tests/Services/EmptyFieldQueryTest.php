<?php

use craft\controllers\ElementIndexesController;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\conditions\EmptyFieldConditionRule;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

/**
 * Builds entries covering the three stored states of a Hyper field: a link, a field cleared after
 * a save (stored as `[]`), and a field Craft never wrote (no key in `elements_sites.content`).
 */
function hyperEmptyQueryFixtures(): array
{
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $section = F::entrySection($field);

    $linked = F::plainEntry($section, 'Linked', [$field->handle => [F::urlLinkPayload('https://example.test/linked', 'Linked')]]);
    $cleared = F::plainEntry($section, 'Cleared', [$field->handle => [F::urlLinkPayload('https://example.test/cleared', 'Cleared')]]);
    $neverSet = F::plainEntry($section, 'Never set');

    $cleared->setFieldValue($field->handle, []);
    expect(Craft::$app->getElements()->saveElement($cleared))->toBeTrue();

    // Confirm the stored shapes the queries must handle.
    $content = fn(Entry $entry) => json_decode((string)(new \craft\db\Query())
        ->select('content')
        ->from(\craft\db\Table::ELEMENTS_SITES)
        ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])
        ->scalar(), true) ?? [];
    $uid = $linked->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    expect($content($cleared))->toHaveKey($uid);
    expect($content($cleared)[$uid])->toBe([]);
    expect($content($neverSet))->not->toHaveKey($uid);

    return [$field, $section, $linked, $cleared, $neverSet];
}

function hyperEmptyQueryIds(Entry ...$entries): array
{
    $ids = array_map(fn(Entry $entry) => $entry->id, $entries);
    sort($ids);

    return $ids;
}

function hyperEmptyQuerySorted(array $ids): array
{
    $ids = array_map('intval', $ids);
    sort($ids);

    return $ids;
}

it('treats a cleared Hyper field as empty in element queries', function() {
    [$field, $section, $linked, $cleared, $neverSet] = hyperEmptyQueryFixtures();
    $query = fn(mixed $value) => hyperEmptyQuerySorted(Entry::find()->sectionId($section->id)->status(null)->{$field->handle}($value)->ids());

    expect($query(':empty:'))->toBe(hyperEmptyQueryIds($cleared, $neverSet));
    expect($query(':EMPTY:'))->toBe(hyperEmptyQueryIds($cleared, $neverSet));
    expect($query(':notempty:'))->toBe(hyperEmptyQueryIds($linked));
    expect($query('not :empty:'))->toBe(hyperEmptyQueryIds($linked));
    expect($query(['not', ':empty:']))->toBe(hyperEmptyQueryIds($linked));

    // Other values keep Craft's default text matching.
    expect($query('*example.test/linked*'))->toBe(hyperEmptyQueryIds($linked));
});

it('keeps the empty condition rule query and element matching in agreement', function() {
    [$field, $section, $linked, $cleared, $neverSet] = hyperEmptyQueryFixtures();
    $entries = Entry::find()->sectionId($section->id)->status(null)->all();
    // Craft's operator constants are protected, so use the stored operator values.
    $expected = [
        'empty' => hyperEmptyQueryIds($cleared, $neverSet),
        'notempty' => hyperEmptyQueryIds($linked),
    ];

    foreach ($expected as $operator => $ids) {
        $condition = Entry::createCondition();
        $rule = $condition->createConditionRule([
            'class' => EmptyFieldConditionRule::class,
            'fieldUid' => $field->uid,
            'operator' => $operator,
        ]);
        $condition->addConditionRule($rule);

        $query = Entry::find()->sectionId($section->id)->status(null);
        $condition->modifyQuery($query);
        $matched = array_values(array_filter($entries, fn(Entry $entry) => $condition->matchElement($entry)));

        expect(hyperEmptyQuerySorted($query->ids()))->toBe($ids, $operator);
        expect(hyperEmptyQueryIds(...$matched))->toBe($ids, $operator);
    }
});

it('filters the control panel entry index by an empty Hyper field', function() {
    [$field, $section, $linked, $cleared, $neverSet] = hyperEmptyQueryFixtures();
    $admin = User::find()->admin()->one();
    $expected = [
        'empty' => hyperEmptyQueryIds($cleared, $neverSet),
        'notempty' => hyperEmptyQueryIds($linked),
    ];
    $ids = function(string $html): array {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        return hyperEmptyQuerySorted(array_unique(array_map(fn($node) => (int)$node->nodeValue, iterator_to_array($xpath->query('//*[@data-id]/@data-id')))));
    };

    foreach ($expected as $operator => $expectedIds) {
        // The index filter HUD posts a condition config, and BaseElementIndex sends the source criteria with it.
        $condition = Entry::createCondition();
        $condition->addConditionRule($condition->createConditionRule([
            'class' => EmptyFieldConditionRule::class,
            'fieldUid' => $field->uid,
            'operator' => $operator,
        ]));

        $response = CpActionRequest::run('get-elements', $admin, [
            'elementType' => Entry::class,
            'context' => 'index',
            'source' => 'section:' . $section->uid,
            'siteId' => $linked->siteId,
            'viewState' => ['mode' => 'table', 'static' => false],
            'baseCriteria' => ['sectionId' => $section->id],
            'condition' => $condition->getConfig(),
        ], controllerClass: ElementIndexesController::class);

        expect($ids($response->data['html']))->toBe($expectedIds, $operator);
    }
});
