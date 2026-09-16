<?php

use craft\base\Field;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\MissingLink;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;

it('retains unavailable legacy types instead of substituting the default link type', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $raw = ['type' => 'unavailable\\links\\Legacy', 'linkValue' => 'legacy:opaque', 'extension' => ['code' => '00123']];
    $collection = new LinkCollection($field, [$raw]);
    expect($collection->getLinks()[0])->toBeInstanceOf(MissingLink::class);
    expect(array_intersect_key($collection->serializeValues()[0], $raw))->toBe($raw);
    $section = F::entrySection($field);
    $owner = F::plainEntry($section, 'Opaque legacy owner', [$field->handle => [$raw]]);
    $saved = $field->serializeValue($owner->getFieldValue($field->handle));
    $owner->title = 'Unrelated edit';
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $owner = Entry::find()->id($owner->id)->one();
    $reloaded = $field->serializeValue($owner->getFieldValue($field->handle));
    ksort($saved[0]);
    ksort($reloaded[0]);
    expect($reloaded)->toBe($saved);
    foreach ($raw as $key => $value) {
        expect($saved[0][$key])->toBe($value);
    }
});

it('recovers an unavailable legacy class when its link type is restored', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $raw = ['type' => \verbb\hyper\links\Email::class, 'linkValue' => 'hello@example.test'];
    $serialized = (new LinkCollection($field, [$raw]))->serializeValues();
    $field->setLinkTypes([F::linkTypeConfig(Url::class), F::linkTypeConfig(\verbb\hyper\links\Email::class)]);
    $restored = new LinkCollection($field, $serialized);
    expect($restored->getLinks()[0])->toBeInstanceOf(\verbb\hyper\links\Email::class);
    expect($restored->getUrl())->toBe('mailto:hello@example.test');
});

it('resolves eager-load paths through layout-specific Hyper and Matrix handles', function() {
    $fixture = F::matrixFieldWithHyper();
    $inner = $fixture['hyperField'];
    $matrix = $fixture['matrix'];
    $innerType = $fixture['blockEntryType'];
    $layout = $innerType->getFieldLayout();
    foreach ($layout->getCustomFieldElements() as $element) {
        if ($element->getFieldUid() === $inner->uid) {
            $element->handle = 'nestedAlias';
        }
    }
    $innerType->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($innerType))->toBeTrue();
    $direct = F::hyperField();
    $section = F::entrySection($direct);
    F::attachFieldToSection($section, $matrix);
    $entryType = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $layout = $entryType->getFieldLayout();
    foreach ($layout->getCustomFieldElements() as $element) {
        $element->handle = $element->getFieldUid() === $direct->uid ? 'directAlias' : 'matrixAlias';
    }
    $entryType->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($entryType))->toBeTrue();
    Hyper::$plugin->linkRelations->resetRequestState();
    $query = Entry::find()->sectionId($section->id)->with([
        'directAlias.linkedElements.relatedEntry',
        'matrixAlias.nestedAlias.linkedElements.thumbnail',
        'author',
    ]);
    Hyper::$plugin->linkedElementEagerLoader->parseWithPaths($query);
    expect($query->with)->toBe(['author']);
    expect(Hyper::$plugin->linkRelations->getLinkedElementWithForField($direct->id))->toBe(['relatedEntry']);
    expect(Hyper::$plugin->linkRelations->getLinkedElementWithForField($inner->id))->toBe(['thumbnail']);
});

it('registers shared Matrix aliases for every matching entry type', function() {
    $first = F::matrixFieldWithHyper();
    $second = F::matrixFieldWithHyper();
    foreach ([$first, $second] as $fixture) {
        $entryType = $fixture['blockEntryType'];
        $layout = $entryType->getFieldLayout();
        foreach ($layout->getCustomFieldElements() as $element) {
            $element->handle = 'sharedLinks';
        }
        $entryType->setFieldLayout($layout);
        expect(Craft::$app->entries->saveEntryType($entryType))->toBeTrue();
    }
    $matrix = $first['matrix'];
    $matrix->setEntryTypes([$first['blockEntryType'], $second['blockEntryType']]);
    expect(Craft::$app->fields->saveField($matrix))->toBeTrue();
    $section = F::entrySection(F::hyperField());
    F::attachFieldToSection($section, $matrix);
    Hyper::$plugin->linkRelations->resetRequestState();
    $query = Entry::find()->sectionId($section->id)->with([$matrix->handle . '.sharedLinks.linkedElements.thumbnail']);
    Hyper::$plugin->linkedElementEagerLoader->parseWithPaths($query);
    expect($query->with)->toBe([]);
    foreach ([$first, $second] as $fixture) {
        expect(Hyper::$plugin->linkRelations->getLinkedElementWithForField($fixture['hyperField']->id))->toBe(['thumbnail']);
    }
});
