<?php

use craft\controllers\ElementIndexesController;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\conditions\TitleConditionRule;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Entry as EntryLink;

it('passes saved conditions from ordinary and bulk pickers into Craft selection and persists the chosen link', function() {
    $link = new EntryLink();
    $condition = Entry::createCondition();
    $condition->setConditionRules([$condition->createConditionRule(['class' => TitleConditionRule::class, 'operator' => '**', 'value' => 'Selectable'])]);
    $link->setSelectionCondition($condition);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($link)], ['multipleLinks' => true]);
    $field->enableBulkAdd = true;
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $section = F::entrySection($field);
    $type = $section->getEntryTypes()[0];
    $layout = $type->getFieldLayout();
    $tabs = $layout->getTabs();
    $tabs[0]->setElements([new \craft\fieldlayoutelements\entries\EntryTitleField(), ...$tabs[0]->getElements()]);
    $layout->setTabs($tabs);
    $type->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
    $allowed = F::plainEntry($section, 'Selectable destination');
    $excluded = F::plainEntry($section, 'Excluded destination');
    $owner = F::plainEntry($section, 'Picker owner');
    expect(Entry::find()->id($allowed->id)->one()->title)->toBe('Selectable destination');
    expect(Entry::find()->sectionId($section->id)->title('*Selectable*')->ids())->toBe([$allowed->id]);
    $admin = User::find()->admin()->one();
    $body = ['fieldId' => $field->id, 'siteId' => $owner->siteId, 'elementId' => $owner->id, 'handle' => 'entry', 'mode' => 'seed', 'seeds' => [[]]];
    $normal = CpActionRequest::run('create-links', $admin, $body);
    $bulk = CpActionRequest::run('bulk-element-select', $admin, $body);
    $scripts = [$normal->data['blocks'][0]['js'], $bulk->data['bodyHtml']];
    foreach ($scripts as $script) {
        // Read the constructor settings emitted by the real Craft picker, not Hyper's config getter.
        preg_match_all('/new Craft\\.BaseElementSelectInput\\((\{[^\n]+?\})\\);/', $script, $matches);
        $settings = array_map(fn($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
        $settings = array_values(array_filter($settings, fn($config) => ($config['elementType'] ?? null) === Entry::class));
        expect($settings)->toHaveCount(1, $script);
        $picker = $settings[0];
        expect($picker['condition']['conditionRules'][0]['value'])->toBe('Selectable');
        // Craft's BaseElementIndex sends source criteria together with the picker criteria.
        $request = ['elementType' => Entry::class, 'context' => 'modal', 'source' => 'section:' . $section->uid, 'siteId' => $owner->siteId, 'condition' => $picker['condition'], 'baseCriteria' => $picker['criteria'] + ['sectionId' => $section->id]];
        $filtered = CpActionRequest::run('get-elements', $admin, $request, controllerClass: ElementIndexesController::class);
        $unfiltered = CpActionRequest::run('get-elements', $admin, array_replace($request, ['condition' => null]), controllerClass: ElementIndexesController::class);
        $ids = function(string $html): array {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            return array_values(array_unique(array_map(fn($node) => (int)$node->nodeValue, iterator_to_array($xpath->query('//*[@data-id]/@data-id')))));
        };
        expect($ids($unfiltered->data['html']))->toContain($allowed->id, $excluded->id);
        expect($ids($filtered->data['html']))->toBe([$allowed->id], json_encode(['settings' => $picker, 'response' => $filtered->data]));

        $created = CpActionRequest::run('create-links', $admin, array_replace($body, ['mode' => 'values', 'values' => [$allowed->id]]));
        expect($created->data['blocks'])->toHaveCount(1);
        $owner->setFieldValue($field->handle, [$created->data['blocks'][0]['serialized']]);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks()[0]->getElement()->id)->toBe($allowed->id);
    }
});
