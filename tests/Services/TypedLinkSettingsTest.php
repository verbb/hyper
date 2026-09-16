<?php

use craft\db\Query;
use craft\fields\PlainText;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\migrations\MigrateTypedLinkField;

it('preserves Typed Link custom-text visibility and requiredness', function(bool $visible, bool $required) {
    $source = new PlainText(['name' => 'Typed settings', 'handle' => F::handle('hyperTestTypedSettings')]);
    expect(Craft::$app->fields->saveField($source))->toBeTrue();
    $row = (new Query())->from('{{%fields}}')->where(['id' => $source->id])->one();
    $row['settings'] = json_encode([
        'allowCustomText' => $visible,
        'customTextRequired' => $required,
        'enableAllLinkTypes' => false,
        'typeSettings' => ['url' => ['enabled' => true]],
    ]);
    $migration = new MigrateTypedLinkField();
    $migration->fields = [$row];
    $migration->processFieldSettings();
    Craft::$app->fields->refreshFields();
    $field = Craft::$app->fields->getFieldById($source->id);
    $text = [];
    foreach ($field->getLinkTypeByHandle('url')->getFieldLayout()->getTabs() as $tab) {
        foreach ($tab->getElements() as $element) {
            if ($element instanceof LinkTextField) $text[] = $element;
        }
    }
    expect($text)->toHaveCount($visible ? 1 : 0);
    if ($visible) expect($text[0]->required)->toBe($required);
})->with([[true, true], [true, false], [false, true]]);
