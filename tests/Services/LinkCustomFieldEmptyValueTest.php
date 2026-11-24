<?php

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Checkboxes;
use craft\fields\Lightswitch;
use craft\fields\MultiSelect;
use craft\fields\Table;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

// The CP store keeps Craft's posted empty values. A stored `null` means "no value yet",
// so Craft applies field defaults on read instead of the editor's off or empty choice.
it('reads posted empty link custom field values as cleared, not as defaults', function() {
    $options = [['label' => 'Alpha', 'value' => 'a', 'default' => true], ['label' => 'Beta', 'value' => 'b', 'default' => false]];
    $toggle = new Lightswitch(['name' => 'Toggle', 'handle' => F::handle('hyperToggle'), 'default' => true]);
    $checks = new Checkboxes(['name' => 'Checks', 'handle' => F::handle('hyperChecks'), 'options' => $options]);
    $multi = new MultiSelect(['name' => 'Multi', 'handle' => F::handle('hyperMulti'), 'options' => $options]);
    $table = new Table([
        'name' => 'Specs',
        'handle' => F::handle('hyperSpecs'),
        'columns' => ['col1' => ['heading' => 'Name', 'handle' => 'name', 'type' => 'singleline'], 'col2' => ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number']],
    ]);

    foreach ([$toggle, $checks, $multi, $table] as $custom) {
        expect(Craft::$app->fields->saveField($custom))->toBeTrue();
    }

    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), ...array_map(fn($custom) => new CustomField($custom), [$toggle, $checks, $multi, $table])]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)], ['multipleLinks' => true]);
    $owner = F::plainEntry(F::entrySection($field), 'Empty values owner');

    // The CP posts its hidden store as JSON: one link as Craft posts the cleared widgets,
    // one with the `null` values that 3.0.0-3.0.6 stored in their place.
    $row = fn($qty) => [['col1' => 'Delta', 'col2' => ['value' => $qty, 'locale' => 'en-US']]];
    $owner->setFieldValue($field->handle, json_encode([
        ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/cleared', 'fields' => [
            $toggle->handle => '', $checks->handle => '', $multi->handle => '', $table->handle => $row(''),
        ]],
        ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/null', 'fields' => [
            $toggle->handle => null, $checks->handle => null, $multi->handle => null, $table->handle => $row(null),
        ]],
    ]));
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();

    $selected = fn($value) => array_map(fn($option) => $option->value, $value->getArrayCopy());
    [$cleared, $stale] = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks();

    expect($cleared->getFieldValue($toggle->handle))->toBeFalse();
    expect($selected($cleared->getFieldValue($checks->handle)))->toBe([]);
    expect($selected($cleared->getFieldValue($multi->handle)))->toBe([]);
    expect($cleared->getFieldValue($table->handle)[0]['qty'])->toBe('');

    // Why the CP must not post `null`: the defaults come back, and the Number cell stays an array.
    expect($stale->getFieldValue($toggle->handle))->toBeTrue();
    expect($selected($stale->getFieldValue($checks->handle)))->toBe(['a']);
    expect($selected($stale->getFieldValue($multi->handle)))->toBe(['a']);
    expect($stale->getFieldValue($table->handle)[0]['qty'])->toBe(['value' => null, 'locale' => 'en-US']);
});
