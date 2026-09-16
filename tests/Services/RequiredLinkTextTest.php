<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Url;

it('requires labels on meaningful rows while allowing unused optional rows', function(string $type, ?string $destination) {
    $prototype = new $type();
    $layout = $type::getDefaultFieldLayout();
    $layout->getField('linkText')->required = true;
    $prototype->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($prototype)]);
    $payload = ['handle' => $prototype->handle, 'linkValue' => $destination, 'classes' => 'section-heading'];
    $owner = F::plainEntry(F::entrySection($field), 'Required label', [$field->handle => [$payload + ['linkText' => 'Original']]]);
    $owner->setAuthorIds([User::find()->admin()->one()->id]);
    $owner->setScenario(Element::SCENARIO_LIVE);
    $owner->setFieldValue($field->handle, [$payload]);
    expect($owner->getFieldValue($field->handle)->first()->isEmpty())->toBeFalse();
    expect(Craft::$app->elements->saveElement($owner))->toBeFalse();
    expect($owner->getErrors($field->handle . '[0].linkText'))->not->toBeEmpty();
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first()->linkText)->toBe('Original');

    $owner->setFieldValue($field->handle, [$payload + ['linkText' => '0']]);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue(json_encode($owner->getErrors()));
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first()->linkText)->toBe('0');

    $empty = $field->normalizeValue([['handle' => $prototype->handle]])->first();
    $empty->setScenario(Element::SCENARIO_LIVE);
    expect($empty->isEmpty())->toBeTrue();
    expect($empty->validate())->toBeTrue(json_encode($empty->getErrors()));
})->with([
    'URL' => [Url::class, 'https://example.test/required'],
    'Passive' => [Passive::class, null],
]);
