<?php

declare(strict_types=1);

use craft\elements\Entry;
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
