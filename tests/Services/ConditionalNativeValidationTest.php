<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\elements\conditions\LinkValueConditionRule;
use verbb\hyper\links\Url;

it('validates native fields only while their saved field and tab conditions match', function(string $scope) {
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $text = $layout->getField('linkText');
    $text->required = true;
    $text->maxlength = 3;
    $text->uid = StringHelper::UUID();
    $condition = Url::createCondition();
    $condition->setConditionRules([$condition->createConditionRule([
        'class' => LinkValueConditionRule::class, 'operator' => '**', 'value' => 'needs-label',
    ])]);
    if ($scope === 'field') {
        $text->setElementCondition($condition);
    } else {
        $tabs = $layout->getTabs();
        $tabs[0]->setElements(array_values(array_filter($tabs[0]->getElements(), fn($element) => $element !== $text)));
        $tabs[1]->setElements([...$tabs[1]->getElements(), $text]);
        $tabs[1]->uid = StringHelper::UUID();
        $tabs[1]->setElementCondition($condition);
    }
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($field), 'Conditional native fields');
    $owner->setAuthorIds([User::find()->admin()->one()->id]);
    $lastSaved = [];
    foreach ([
        ['/optional', null, true],
        ['/needs-label', null, false],
        ['/needs-label', 'Too long', false],
        ['/needs-label', 'OK', true],
        ['/optional', 'Retained existing label', true],
    ] as [$destination, $label, $valid]) {
        $owner->setFieldValue($field->handle, [['handle' => 'url', 'linkValue' => $destination, 'linkText' => $label]]);
        $owner->setScenario(Element::SCENARIO_LIVE);
        $link = $owner->getFieldValue($field->handle)->first();
        $visible = array_map(fn($element) => $element->attribute(), $link->getFieldLayout()->getVisibleElementsByType(BaseNativeField::class, $link));
        expect(in_array('linkText', $visible, true))->toBe($destination === '/needs-label');
        expect(Craft::$app->elements->saveElement($owner))->toBe($valid, json_encode($owner->getErrors()));
        if ($valid) {
            $lastSaved = [$destination, $label];
        } else {
            expect($owner->getErrors($field->handle . '[0].linkText'))->not->toBeEmpty();
        }
        $saved = Entry::find()->id($owner->id)->status(null)->one()->getFieldValue($field->handle)->first();
        expect([$saved->linkValue, $saved->linkText])->toBe($lastSaved);
    }

    // Yii caches validators: conditions must follow edits to this same instance.
    $link = $owner->getFieldValue($field->handle)->first();
    expect($link->validate())->toBeTrue();
    $link->linkValue = '/needs-label';
    expect($link->validate())->toBeFalse();
    $link->linkText = 'OK';
    expect($link->validate())->toBeTrue();
    $link->linkValue = '/optional';
    $link->linkText = null;
    expect($link->validate())->toBeTrue();
})->with(['field', 'tab']);

it('applies the required Hyper destination rule only when its input is visible', function() {
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $destination = $layout->getField('linkValue');
    $destination->uid = StringHelper::UUID();
    $condition = Url::createCondition();
    $condition->setConditionRules([$condition->createConditionRule([
        'class' => LinkValueConditionRule::class, 'operator' => '**', 'value' => 'show-input',
    ])]);
    $destination->setElementCondition($condition);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $link = $field->normalizeValue([['handle' => 'url', 'linkText' => 'Retained label']])->first();
    $link->isFieldRequired = true;
    $link->setScenario(Element::SCENARIO_LIVE);
    $visible = array_map(fn($element) => $element->attribute(), $link->getFieldLayout()->getVisibleElementsByType(BaseNativeField::class, $link));
    expect($visible)->not->toContain('linkValue');
    expect($link->validate())->toBeTrue(json_encode($link->getErrors()));
});
