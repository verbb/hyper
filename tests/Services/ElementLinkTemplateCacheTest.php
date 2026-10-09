<?php

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;

it('invalidates real Twig fragments when an element destination changes', function(string $mode) {
    $field = F::hyperField(['multipleLinks' => true]);
    $section = F::entrySection($field);
    $type = Craft::$app->getEntries()->getEntryTypesBySectionId($section->id)[0];
    $layout = $type->getFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([new craft\fieldlayoutelements\entries\EntryTitleField(), ...$tab->getElements()]);
    $layout->setTabs([$tab]);
    $type->setFieldLayout($layout);
    expect(Craft::$app->getEntries()->saveEntryType($type))->toBeTrue();
    $target = F::plainEntry($section, 'Original destination');
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [F::entryLinkPayload($target, '')]]);
    $key = F::handle('destinationFragment');
    $config = Craft::$app->getConfig()->getGeneral();
    $previous = $config->enableTemplateCaching;
    $config->enableTemplateCaching = true;

    $render = function() use ($field, $owner, $key, $mode) {
        Hyper::$plugin->getLinkRelations()->resetRequestState();
        $query = Entry::find()->id($owner->id);
        if ($mode === 'eager') {
            $query->with([$field->handle . '.linkedElements']);
        }
        $links = $query->one()->getFieldValue($field->handle);
        if ($mode === 'primed') {
            $links->getUrl();
        }
        return Craft::$app->getView()->renderString(
            '{% cache globally using key key %}{{ random() }}|{% for link in links %}{{ link.getLink() }}{% endfor %}{% endcache %}',
            ['key' => $key, 'links' => $links],
        );
    };

    try {
        $original = $render();
        expect($render())->toBe($original);
        expect($original)->toContain('Original destination');

        $target->title = 'Changed destination';
        $target->slug = 'changed-destination';
        expect(Craft::$app->getElements()->saveElement($target))->toBeTrue();
        $changed = $render();
        expect($changed)->not->toBe($original)->toContain('Changed destination')->toContain('changed-destination');
        expect($render())->toBe($changed);

        $target->enabled = false;
        expect(Craft::$app->getElements()->saveElement($target))->toBeTrue();
        $disabled = $render();
        expect($disabled)->not->toContain('<a ');
        expect($render())->toBe($disabled);

        $target->enabled = true;
        expect(Craft::$app->getElements()->saveElement($target))->toBeTrue();
        expect($render())->toContain('Changed destination');
    } finally {
        $config->enableTemplateCaching = $previous;
    }
})->with(['plain', 'eager', 'primed']);
