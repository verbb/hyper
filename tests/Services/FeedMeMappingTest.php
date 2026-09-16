<?php

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\feedme\Plugin as FeedMe;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\records\LinkRelation;

beforeEach(function() {
    if (!Craft::$app->plugins->isPluginInstalled('feed-me')) {
        Craft::$app->plugins->installPlugin('feed-me');
    }
});

it('imports ordered links and custom fields through the registered Feed Me mapper', function(bool $multiple) {
    $caption = new PlainText(['name' => 'Import caption', 'handle' => F::handle('importCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $link = new EntryLink();
    $layout = EntryLink::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption)]);
    $link->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($link)], ['multipleLinks' => $multiple]);
    $section = F::entrySection($field);
    $targets = [F::plainEntry($section, 'First destination'), F::plainEntry($section, 'Second destination')];
    $owner = F::plainEntry($section, 'Import owner');
    $mapper = FeedMe::$plugin->fields->getRegisteredField(HyperField::class);
    expect($mapper)->toBeInstanceOf(\verbb\hyper\integrations\feedme\fields\Hyper::class);
    $mapper->field = $field;
    $mapper->element = $owner;
    $mapper->feed = ['id' => null, 'setEmptyValues' => true];
    $mapper->fieldInfo = ['fields' => [
        'type' => ['node' => 'usedefault', 'default' => 'entry'],
        'linkValue' => ['node' => 'links/id'],
        'linkText' => ['node' => 'links/text'],
        $caption->handle => ['node' => 'links/caption', 'default' => 'Unused default'],
    ]];
    $mapper->feedData = [];
    $selected = $multiple ? $targets : [$targets[0]];
    foreach ($selected as $i => $target) {
        $mapper->feedData['links/' . $i . '/id'] = (string)$target->id;
        $mapper->feedData['links/' . $i . '/text'] = 'Link ' . $i;
        $mapper->feedData['links/' . $i . '/caption'] = 'Caption ' . $i;
    }
    try {
        $owner->setFieldValue($field->handle, $mapper->parseField());
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        $links = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks();
        expect($links)->toHaveCount(count($selected));
        foreach ($links as $i => $imported) {
            expect($imported->getElement()->id)->toBe($selected[$i]->id);
            expect($imported->getUrl())->toBe($selected[$i]->getUrl());
            expect($imported->getLinkText())->toBe('Link ' . $i);
            expect($imported->getFieldValue($caption->handle))->toBe('Caption ' . $i);
        }
        expect(array_map('intval', LinkRelation::find()->select('targetId')->where(['ownerId' => $owner->id])->orderBy('sortOrder')->column()))->toBe(array_map(fn($target) => $target->id, $selected));

        // Remove the fallback before checking an explicitly empty import.
        unset($mapper->fieldInfo['fields'][$caption->handle]['default']);
        // Feed Me uses null for an unmapped field and [] for an explicitly empty import.
        $mapper->feedData = ['unrelated' => 'leave links alone'];
        expect($mapper->parseField())->toBeNull();
        $mapper->feedData = ['links/0/id' => '', 'links/0/text' => '', 'links/0/caption' => ''];
        expect($mapper->parseField())->toBe([]);
        $owner->setFieldValue($field->handle, $mapper->parseField());
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks())->toBe([]);
        expect(LinkRelation::find()->where(['ownerId' => $owner->id])->exists())->toBeFalse();
    } finally {
        Craft::$app->fields->deleteField($caption);
    }
})->with(['single' => false, 'multiple' => true]);

it('keeps nested table rows attached to their imported link', function(bool $multiple) {
    $table = new Table(['name' => 'Details', 'handle' => F::handle('importDetails'), 'columns' => ['col1' => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline']]]);
    expect(Craft::$app->fields->saveField($table))->toBeTrue();
    $link = new \verbb\hyper\links\Url();
    $layout = $link::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($table)]);
    $link->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($link)], ['multipleLinks' => $multiple]);
    $owner = F::plainEntry(F::entrySection($field), 'Nested import');
    $mapper = FeedMe::$plugin->fields->getRegisteredField(HyperField::class);
    $mapper->field = $field;
    $mapper->element = $owner;
    $mapper->feed = ['id' => null, 'setEmptyValues' => true];
    $mapper->fieldInfo = ['fields' => [
        'type' => ['node' => 'usedefault', 'default' => 'url'],
        'linkValue' => ['node' => 'links/url'],
        $table->handle => ['field' => Table::class, 'fields' => ['col1' => ['node' => 'links/details/label', 'type' => 'singleline']]],
    ]];
    $mapper->feedData = [];
    $count = $multiple ? 2 : 1;
    foreach (range(0, $count - 1) as $i) {
        $mapper->feedData["links/$i/url"] = "https://example.test/$i";
        foreach ([0, 1] as $row) {
            $mapper->feedData["links/$i/details/$row/label"] = "Link $i row $row";
        }
    }
    try {
        $owner->setFieldValue($field->handle, $mapper->parseField());
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        $links = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks();
        expect($links)->toHaveCount($count);
        foreach ($links as $i => $imported) {
            expect($imported->getUrl())->toBe("https://example.test/$i");
            expect(array_column($imported->getFieldValue($table->handle), 'label'))->toBe(["Link $i row 0", "Link $i row 1"]);
        }
    } finally {
        Craft::$app->fields->deleteField($table);
    }
})->with(['single' => false, 'multiple' => true]);

it('preserves zero values when an imported link uses a default type', function(string $class, string $type, string $attribute, bool $multiple, mixed $zero) {
    $field = F::hyperField(['linkTypes' => [$class], 'multipleLinks' => $multiple]);
    $owner = F::plainEntry(F::entrySection($field), 'Zero import');
    $mapper = FeedMe::$plugin->fields->getRegisteredField(HyperField::class);
    $mapper->field = $field;
    $mapper->element = $owner;
    $mapper->feed = ['id' => null, 'setEmptyValues' => true];
    $mapper->fieldInfo = ['fields' => [
        'type' => ['node' => 'usedefault', 'default' => $type],
        $attribute => ['node' => 'links/value'],
    ]];
    $mapper->feedData = ['links/0/value' => $zero];

    $imported = $mapper->parseField();
    expect($imported)->toHaveCount(1);
    $owner->setFieldValue($field->handle, $imported);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $link = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
    expect($link)->not->toBeNull();
    expect($attribute === 'linkValue' ? $link->getUrl() : $link->getText())->toBe($attribute === 'linkValue' ? 'tel:0' : '0');
})->with([
    'phone' => [\verbb\hyper\links\Phone::class, 'tel', 'linkValue'],
    'passive label' => [\verbb\hyper\links\Passive::class, 'passive', 'linkText'],
])->with(['single' => false, 'multiple' => true])->with(['string zero' => '0', 'integer zero' => 0]);

it('imports default custom values through the registered Feed Me mapper', function(bool $multiple, string $default) {
    $caption = new PlainText(['name' => 'Import caption', 'handle' => F::handle('importCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $link = new EntryLink();
    $layout = EntryLink::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption)]);
    $link->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($link)], ['multipleLinks' => $multiple]);
    $section = F::entrySection($field);
    $targets = [F::plainEntry($section, 'First destination'), F::plainEntry($section, 'Second destination')];
    $owner = F::plainEntry($section, 'Import owner');
    $mapper = FeedMe::$plugin->fields->getRegisteredField(HyperField::class);
    expect($mapper)->toBeInstanceOf(\verbb\hyper\integrations\feedme\fields\Hyper::class);
    $mapper->field = $field;
    $mapper->element = $owner;
    $mapper->feed = ['id' => null, 'setEmptyValues' => true];
    $mapper->fieldInfo = ['fields' => [
        'type' => ['node' => 'usedefault', 'default' => 'entry'],
        'linkValue' => ['node' => 'links/id'],
        'linkText' => ['node' => 'links/text'],
        $caption->handle => ['node' => 'usedefault', 'default' => $default],
    ]];
    $mapper->feedData = [];
    $selected = $multiple ? $targets : [$targets[0]];
    foreach ($selected as $i => $target) {
        $mapper->feedData['links/' . $i . '/id'] = (string)$target->id;
        $mapper->feedData['links/' . $i . '/text'] = 'Link ' . $i;
    }
    try {
        $owner->setFieldValue($field->handle, $mapper->parseField());
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        $links = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks();
        expect($links)->toHaveCount(count($selected));
        foreach ($links as $i => $imported) {
            expect($imported->getElement()->id)->toBe($selected[$i]->id);
            expect($imported->getUrl())->toBe($selected[$i]->getUrl());
            expect($imported->getLinkText())->toBe('Link ' . $i);
            expect($imported->getFieldValue($caption->handle))->toBe($default);
        }
        expect(array_map('intval', LinkRelation::find()->select('targetId')->where(['ownerId' => $owner->id])->orderBy('sortOrder')->column()))->toBe(array_map(fn($target) => $target->id, $selected));

        // Feed Me uses null for an unmapped field and [] for an explicitly empty import.
        $mapper->feedData = ['unrelated' => 'leave links alone'];
        expect($mapper->parseField())->toBeNull();
        $mapper->feedData = ['links/0/id' => '', 'links/0/text' => '', 'links/0/caption' => ''];
        expect($mapper->parseField())->toBe([]);
        $owner->setFieldValue($field->handle, $mapper->parseField());
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks())->toBe([]);
        expect(LinkRelation::find()->where(['ownerId' => $owner->id])->exists())->toBeFalse();
    } finally {
        Craft::$app->fields->deleteField($caption);
    }
})->with(['single' => false, 'multiple' => true])->with(['Default caption', '0']);

