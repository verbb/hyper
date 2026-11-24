<?php

declare(strict_types=1);

use craft\db\Paginator;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;

it('keeps reverse relation owner and site pairs together', function() {
    [$s1, $s2] = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField(['translationMethod' => \craft\base\Field::TRANSLATION_METHOD_SITE]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    $target = HyperFixtureFactory::plainEntry($section, 'Target', [], $s1);
    $payload = HyperFixtureFactory::entryLinkPayload($target) + ['linkSiteId' => $s1->id];
    $a = HyperFixtureFactory::plainEntry($section, 'A', [$field->handle => [$payload]], $s1);
    $a2 = HyperFixtureFactory::localizedEntryForSite($a, $s2);
    $a2->setFieldValue($field->handle, []);
    expect(Craft::$app->elements->saveElement($a2))->toBeTrue();
    $b = HyperFixtureFactory::plainEntry($section, 'B', [], $s1);
    $b2 = HyperFixtureFactory::localizedEntryForSite($b, $s2);
    $b2->setFieldValue($field->handle, [$payload]);
    expect(Craft::$app->elements->saveElement($b2))->toBeTrue();
    $params = ['relatedTo' => ['field' => $field->handle, 'targetElement' => $target], 'site' => '*'];
    $pairs = fn($query) => array_map(fn($e) => $e->id . ':' . $e->siteId, $query->all());
    expect($pairs(Hyper::$plugin->linkRelations->getRelatedElementsQuery($params)))
        ->toEqualCanonicalizing([$a->id . ':' . $s1->id, $b->id . ':' . $s2->id]);
    $params['site'] = $s1->handle;
    expect($pairs(Hyper::$plugin->linkRelations->getRelatedElementsQuery($params)))
        ->toBe([$a->id . ':' . $s1->id]);
    $params['site'] = '*';
    $params['criteria'] = ['id' => $b->id];
    expect($pairs(Hyper::$plugin->linkRelations->getRelatedElementsQuery($params)))
        ->toBe([$b->id . ':' . $s2->id]);
});

it('returns the same reverse relation rows as per-owner pair matching across sites', function() {
    [$s1, $s2] = HyperFixtureFactory::ensureSites(2);
    $field = HyperFixtureFactory::hyperField(['translationMethod' => \craft\base\Field::TRANSLATION_METHOD_SITE]);
    $otherField = HyperFixtureFactory::hyperField(['translationMethod' => \craft\base\Field::TRANSLATION_METHOD_SITE]);
    $section = HyperFixtureFactory::translatableEntrySection($field, 2);
    HyperFixtureFactory::attachHyperFieldToSection($section, $otherField);
    $target = HyperFixtureFactory::plainEntry($section, 'Reverse parity target', [], $s1);
    $otherTarget = HyperFixtureFactory::plainEntry($section, 'Reverse parity other target', [], $s1);
    $emptyTarget = HyperFixtureFactory::plainEntry($section, 'Reverse parity empty target', [], $s1);

    // Every owner exists on both sites, so independent ID and site filters would return extra translations.
    $ownerIds = [];

    for ($i = 0; $i < 300; $i++) {
        $ownerIds[] = HyperFixtureFactory::plainEntry($section, 'Reverse parity owner ' . $i, [], $s1)->id;
    }

    // Write the derived index directly with a mix of site pairings, duplicate rows and rows that must not match.
    $rows = [];
    $expected = [];
    $row = static fn(int $fieldId, int $ownerId, int $ownerSiteId, int $sortOrder, int $targetId, int $targetSiteId): array => [$fieldId, $ownerId, $ownerSiteId, $sortOrder, 'entry', $targetId, $targetSiteId, Entry::class];

    // Rows go in a stride order rather than ID order, so fixed order has a distinct link order to follow.
    foreach (array_keys($ownerIds) as $n) {
        $i = ($n * 7) % count($ownerIds);
        $ownerId = $ownerIds[$i];
        $ownerSites = match ($i % 4) {
            0 => [$s1->id],
            1 => [$s2->id],
            2 => [$s1->id, $s2->id],
            default => [],
        };

        foreach ($ownerSites as $siteId) {
            $rows[] = $row($field->id, $ownerId, $siteId, 0, $target->id, $s1->id);
            $expected[] = $ownerId . ':' . $siteId;
        }

        if ($i % 4 === 2) {
            $rows[] = $row($field->id, $ownerId, $s1->id, 1, $target->id, $s1->id);
        }

        if ($i % 4 === 3) {
            $rows[] = $row($field->id, $ownerId, $s1->id, 0, $target->id, $s2->id);
            $rows[] = $row($otherField->id, $ownerId, $s2->id, 0, $target->id, $s1->id);
            $rows[] = $row($field->id, $ownerId, $s2->id, 0, $otherTarget->id, $s1->id);
        }
    }

    foreach (array_chunk($rows, 500) as $batch) {
        Craft::$app->getDb()->createCommand()->batchInsert('{{%hyper_links}}', ['fieldId', 'ownerId', 'ownerSiteId', 'sortOrder', 'linkTypeHandle', 'targetId', 'targetSiteId', 'targetType'], $batch)->execute();
    }

    // The 3.0.6 implementation, kept as the reference result.
    $legacy = static function(array $params): ElementQueryInterface {
        $hyperField = Craft::$app->getFields()->getFieldByHandle($params['relatedTo']['field']);
        $targetElement = $params['relatedTo']['targetElement'];
        $result = (new Query())
            ->select(['ownerId AS id', 'ownerSiteId AS siteId'])
            ->from(['{{%hyper_links}}'])
            ->where([
                'fieldId' => $hyperField->id,
                'targetType' => $targetElement::class,
                'targetId' => $targetElement->id,
                'targetSiteId' => $targetElement->siteId,
            ])
            ->indexBy(static fn(array $row): string => $row['id'] . ':' . $row['siteId'])
            ->all();
        $elementParams = [];

        foreach ($result as $value) {
            $elementParams['id'][] = $value['id'];
            $elementParams['siteId'][] = $value['siteId'];
        }

        if (!$elementParams) {
            $elementParams['id'] = false;
        }

        $elementParams['site'] = $params['site'] ?? Craft::$app->getSites()->getCurrentSite()->handle;

        foreach ($params['criteria'] ?? [] as $key => $value) {
            if ($key === 'id') {
                $elementParams['where'] = Db::parseParam('id', $value);
            } else {
                $elementParams[$key] = $value;
            }
        }

        $elementQuery = Entry::find();
        Craft::configure($elementQuery, $elementParams);
        $pairs = array_map(static fn(array $row): array => [
            'elements.id' => (int)$row['id'],
            'elements_sites.siteId' => (int)$row['siteId'],
        ], array_values($result));
        $elementQuery->andWhere($pairs ? ['or', ...$pairs] : '0=1');

        return $elementQuery;
    };

    $current = static fn(array $params): ElementQueryInterface => Hyper::$plugin->linkRelations->getRelatedElementsQuery($params);
    $pairs = static fn(array $elements): array => array_map(static fn($element): string => $element->id . ':' . $element->siteId, $elements);
    $ordered = static fn(ElementQueryInterface $query): ElementQueryInterface => $query->orderBy(['elements.id' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC]);
    $relatedTo = ['field' => $field->handle, 'targetElement' => $target];

    $all = $pairs($ordered($current(['relatedTo' => $relatedTo, 'site' => '*']))->all());
    expect($all)->toEqualCanonicalizing($expected);
    expect(count($all))->toBe(count(array_unique($all)));

    $subset = array_slice($ownerIds, 0, 12);
    $scenarios = [
        'all sites' => ['site' => '*'],
        'primary site' => ['site' => $s1->handle],
        'secondary site' => ['site' => $s2->handle],
        'current site' => [],
        'all sites with ids' => ['site' => '*', 'criteria' => ['id' => $subset]],
        'all sites excluding an id' => ['site' => '*', 'criteria' => ['id' => 'not ' . $ownerIds[2]]],
        'all sites with siteId criteria' => ['site' => '*', 'criteria' => ['siteId' => $s2->id]],
        'secondary site with section and status' => ['site' => $s2->handle, 'criteria' => ['section' => $section->handle, 'status' => null]],
        'all sites with criteria limit' => ['site' => '*', 'criteria' => ['limit' => 7, 'offset' => 3, 'orderBy' => ['elements.id' => SORT_DESC, 'elements_sites.siteId' => SORT_DESC]]],
    ];

    foreach ($scenarios as $label => $scenario) {
        $params = ['relatedTo' => $relatedTo] + $scenario;
        $reference = $pairs($ordered($legacy($params))->all());

        expect($reference)->not->toBeEmpty();
        expect($pairs($ordered($current($params))->all()))->toBe($reference, $label);
        expect($current($params)->count())->toBe($legacy($params)->count(), $label);
        expect(array_map('intval', $ordered($current($params))->ids()))->toBe(array_map('intval', $ordered($legacy($params))->ids()), $label);

        if (!isset($scenario['criteria']['limit'])) {
            expect($pairs($ordered($current($params))->limit(10)->offset(5)->all()))->toBe($pairs($ordered($legacy($params))->limit(10)->offset(5)->all()), $label);
        }

        $currentPages = new Paginator($ordered($current($params)), ['pageSize' => 25]);
        $legacyPages = new Paginator($ordered($legacy($params)), ['pageSize' => 25]);
        expect($currentPages->getTotalResults())->toBe($legacyPages->getTotalResults(), $label);
        expect($currentPages->getTotalPages())->toBe($legacyPages->getTotalPages(), $label);

        foreach (array_unique([1, 2, $legacyPages->getTotalPages()]) as $page) {
            $currentPages->setCurrentPage($page);
            $legacyPages->setCurrentPage($page);
            expect($pairs($currentPages->getPageResults()))->toBe($pairs($legacyPages->getPageResults()), $label . ' page ' . $page);
        }
    }

    // Fixed order follows the recorded link order. An owner's rows on both sites tie, so compare
    // the ID sequence exactly and the pairs as a set.
    $ids = static fn(array $elements): array => array_map(static fn($element): int => (int)$element->id, $elements);
    $fixedOrderScenarios = [
        'all sites with fixed order' => ['site' => '*', 'criteria' => ['fixedOrder' => true]],
        'primary site with fixed order' => ['site' => $s1->handle, 'criteria' => ['fixedOrder' => true]],
        'all sites with ids and fixed order' => ['site' => '*', 'criteria' => ['id' => $subset, 'fixedOrder' => true]],
    ];

    foreach ($fixedOrderScenarios as $label => $scenario) {
        $params = ['relatedTo' => $relatedTo] + $scenario;
        $reference = $legacy($params)->all();

        expect($ids($reference))->not->toBe($ids($ordered($legacy($params))->all()), $label);
        expect($ids($current($params)->all()))->toBe($ids($reference), $label);
        expect($pairs($current($params)->all()))->toEqualCanonicalizing($pairs($reference), $label);
        expect($current($params)->count())->toBe($legacy($params)->count(), $label);

        $currentPages = new Paginator($current($params), ['pageSize' => 25]);
        $legacyPages = new Paginator($legacy($params), ['pageSize' => 25]);
        expect($currentPages->getTotalResults())->toBe($legacyPages->getTotalResults(), $label);
        expect($ids($currentPages->getPageResults()))->toBe($ids($legacyPages->getPageResults()), $label . ' page 1');
    }

    // A target without incoming links still returns an empty query rather than null.
    $empty = $current(['relatedTo' => ['field' => $field->handle, 'targetElement' => $emptyTarget], 'site' => '*']);
    expect($empty)->not->toBeNull();
    expect($empty->all())->toBe([]);
    expect($empty->count())->toBe(0);
});

it('uses batch-primed elements when resolving entry links after a query', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target')],
        'Target entry',
    );
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, 'Points at target')],
        'Owner entry',
    );

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $entries = Entry::find()->section($section->handle)->id($owner->id)->all();
    $links = $entries[0]->getFieldValue($field->handle);
    $link = $links->getLinks()[0];

    expect($link->getElement())->not->toBeNull();
    expect($link->getElement()->id)->toBe($target->id);
});

it('resolves entry link url and text without hyper_element_cache rows', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target-page')],
        'Target entry',
    );
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, 'Custom nav label')],
        'Owner entry',
    );

    $db = Craft::$app->getDb();

    if ($db->tableExists('{{%hyper_element_cache}}')) {
        $db->createCommand()
            ->delete('{{%hyper_element_cache}}', [
                'sourceId' => $owner->id,
                'fieldId' => $field->id,
            ])
            ->execute();

        expect((int)(new \craft\db\Query())
            ->from('{{%hyper_element_cache}}')
            ->where(['sourceId' => $owner->id, 'fieldId' => $field->id])
            ->count())->toBe(0);
    }

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $entries = Entry::find()->section($section->handle)->id($owner->id)->all();
    $link = $entries[0]->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getUrl())->not->toBeNull();
    expect($link->getLinkText())->toBe('Custom nav label');
});
