<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Date;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\base\Link;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Url;

it('uses the same filtered selection for all collection reads regardless of the field mode', function(bool $multiple) {
    $field = F::hyperField(['multipleLinks' => $multiple]);
    $links = $field->normalizeValue([
        F::urlLinkPayload('', 'Unfinished'),
        F::urlLinkPayload('https://example.test/first', 'First'),
        F::urlLinkPayload('   ', 'Whitespace'),
        F::urlLinkPayload('#section', 'Section'),
    ]);

    expect($links->count())->toBe(2);
    expect(iterator_to_array($links))->toBe($links->all());
    expect($links[0])->toBe($links->first());
    expect($links[1]->getUrl())->toBe('#section');
    expect($links[2])->toBeNull();
    expect($links->url)->toBe('https://example.test/first');
    expect((string)$links)->toBe('https://example.test/first');
    expect((string)$links->getLink())->toContain('>First</a>');
    expect($links->exists())->toBeTrue();
    expect($links->isEmpty())->toBeFalse();
    expect($links->getLinks())->toHaveCount(4);

    // Replacing/removing a selected position must not touch an earlier hidden row.
    $links[0] = F::urlLinkPayload('https://example.test/replaced', 'Replaced');
    unset($links[1]);
    expect($links->getUrl())->toBe('https://example.test/replaced');
    expect(array_values($links->getLinks())[0]->getCustomLinkText())->toBe('Unfinished');
    expect($links->getLinks())->toHaveCount(3);
})->with([false, true]);

it('keeps empty scope independent of Boolean filters and sibling selections', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $links = $field->normalizeValue([
        F::urlLinkPayload('', 'Empty'),
        F::urlLinkPayload('https://example.test/a', 'A'),
        F::urlLinkPayload('https://example.test/b', 'B'),
        F::urlLinkPayload('https://example.test/c', 'A'),
    ]);
    $a = $links->where(['linkText' => 'A']);
    $b = $links->where(['linkText' => 'B']);
    $both = $a->orWhere(['linkText' => ['Empty', 'B', 'A']]);

    expect($a->count())->toBe(2);
    expect($b->count())->toBe(1);
    expect($both->all())->toBe($links->all());
    expect($links->where(['not', ['linkText' => 'A']])->all())->toBe($b->all());
    expect($both->empty(null)->count())->toBe(4);
    expect($both->empty(true)->count())->toBe(1);
    expect($both->empty(true)->where(['handle' => 'url'])->first()->getCustomLinkText())->toBe('Empty');
    expect($both->where(['linkText' => 'Empty'])->first())->toBeNull();
    expect($links->count())->toBe(3);
    expect($a->first())->toBe($links->first());
    expect($a->serializeValues())->toBe($links->serializeValues());
});

it('supports custom field comparisons and stable ordering without rehydrating links', function() {
    $fields = [
        new PlainText(['name' => 'Category', 'handle' => F::handle('category')]),
        new Number(['name' => 'Priority', 'handle' => F::handle('priority')]),
        new Lightswitch(['name' => 'Featured', 'handle' => F::handle('featured')]),
        new Date(['name' => 'Published', 'handle' => F::handle('published')]),
    ];
    foreach ($fields as $field) {
        expect(Craft::$app->fields->saveField($field))->toBeTrue();
    }
    [$category, $priority, $featured, $published] = array_map(fn($field) => $field->handle, $fields);
    $url = new Url(['handle' => 'resource', 'isCustom' => true]);
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), ...array_map(fn($field) => new CustomField($field), $fields)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(Url::class)]);
    $links = $field->normalizeValue([
        ['handle' => 'resource', 'linkValue' => '/a', 'linkText' => 'A', 'fields' => [$category => 'News', $priority => 2, $featured => true, $published => '2026-09-20']],
        ['handle' => 'resource', 'linkValue' => '/b', 'linkText' => 'B', 'fields' => [$category => 'Guides', $priority => 1, $featured => false, $published => '2026-09-10']],
        ['handle' => 'resource', 'linkValue' => '/c', 'linkText' => 'C', 'fields' => [$category => 'News', $priority => 2, $featured => true, $published => '2026-09-21']],
        ['handle' => 'url', 'linkValue' => '/d', 'linkText' => 'D'],
    ]);

    $resources = $links->where(['handle' => 'resource']);
    expect($resources->andWhere([$category => 'News', $featured => true])->count())->toBe(2);
    expect($resources->andWhere(['>', $priority, 1])->count())->toBe(2);
    expect($resources->andWhere(['between', $published, new DateTime('2026-09-19'), new DateTime('2026-09-22')])->count())->toBe(2);
    expect($resources->andWhere(['like', 'fields.' . $category, 'new'])->count())->toBe(2);
    expect($links->where(['fields.' . $category => null])->first()->getCustomLinkText())->toBe('D');
    expect(array_map(fn($link) => $link->getCustomLinkText(), $resources->orderBy($priority . ' DESC')->all()))->toBe(['A', 'C', 'B']);
    expect($resources->orderBy([$priority => SORT_ASC])->first()->getCustomLinkText())->toBe('B');
    expect($resources->orderBy($priority . ' DESC')->offset(1)->limit(1)->first())->toBe($links[2]);
    expect($resources->limit(0)->all())->toBe([]);
    expect($resources->limit(1)->count())->toBe(1);
    expect($resources->limit(1)->limit(null)->count())->toBe(3);
    expect(fn() => $links->where(['bogus', 'linkText', 'A']))->toThrow(InvalidArgumentException::class);
    expect(fn() => $links->limit(-1))->toThrow(InvalidArgumentException::class);
});

it('renders the documented Twig API with correct loop metadata and empty branches', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $links = $field->normalizeValue([
        F::urlLinkPayload('', 'Empty'),
        F::urlLinkPayload('/a', 'A'),
        F::urlLinkPayload('/b', 'B'),
    ]);
    $view = Craft::$app->getView();
    $template = '{% for link in links %}{{ loop.index }}/{{ loop.length }}:{{ link.text }}:{{ loop.last ? "last" : "more" }};{% else %}none{% endfor %}';
    expect($view->renderString($template, ['links' => $links]))->toBe('1/2:A:more;2/2:B:last;');
    expect($view->renderString($template, ['links' => $links->where(['handle' => 'absent'])]))->toBe('none');
    expect($view->renderString('{{ links.where({linkText: "B"}).getLink() }}', ['links' => $links]))->toContain('href="/b"')->toContain('>B</a>');
    expect($view->renderString('{{ links.empty(null)|length }}:{{ links|length }}:{{ links.url }}', ['links' => $links]))->toBe('3:2:/a');
    expect($links->where(['handle' => 'absent'])->getLink())->toBeNull();
    expect(fn() => $links->getElement())->toThrow(\yii\base\UnknownMethodException::class);
    expect(fn() => $links->unknownField)->toThrow(\yii\base\UnknownPropertyException::class);
});

it('uses resolved destinations while preserving unfinished passive and unavailable content on save', function() {
    $field = F::hyperField(['multipleLinks' => true, 'linkTypes' => [Url::class, Passive::class, \verbb\hyper\links\Entry::class]]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Disabled target');
    $target->enabled = false;
    expect(Craft::$app->elements->saveElement($target))->toBeTrue();
    $owner = F::plainEntry($section, 'Mixed links', [$field->handle => [
        F::urlLinkPayload('', 'Unfinished label'),
        F::passiveLinkPayload('Navigation heading'),
        F::entryLinkPayload($target, 'Disabled'),
        ['linkTypeHandle' => 'uninstalled', 'linkValue' => 'https://example.test/unknown', 'fields' => ['opaque' => 'preserved']],
        F::urlLinkPayload('/valid', 'Valid'),
    ]]);
    $owner = Entry::find()->id($owner->id)->one();
    $links = $owner->getFieldValue($field->handle);
    expect($links->count())->toBe(1);
    expect($links->empty(true)->count())->toBe(4);
    expect($links->getLinks())->toHaveCount(5);
    expect($links->empty(null)->first()->isEmpty())->toBeTrue();
    expect(Link::isInstanceEmpty($links->empty(null)->first()->toInstance()))->toBeFalse();
    expect($field->getPreviewHtml($links->where(['linkText' => 'Valid'])->limit(0), $owner))->toBe($field->getPreviewHtml($links, $owner));
    $before = $links->serializeValues();
    $owner->setFieldValue($field->handle, $links->where(['linkText' => 'Valid']));
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $reloaded = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle);
    expect($reloaded->serializeValues())->toBe($before);
    expect($reloaded->getLinks()[3]->getSerializedValues()['fields']['opaque'])->toBe('preserved');
    // CP rendering must retain hidden rows rather than rebuilding from iteration.
    $input = (new ReflectionMethod($field, '_getLinksForInput'))->invoke($field, $reloaded, 'test', $owner);
    expect($input)->toHaveCount(5);
    expect(array_column($input, 'linkText'))->toBe(['Unfinished label', 'Navigation heading', 'Disabled', null, 'Valid']);
});

it('validates authored rows even when they are excluded from template iteration', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $owner = F::plainEntry(F::entrySection($field), 'Invalid link owner');
    $owner->setAuthorIds([User::find()->admin()->one()->id]);
    $owner->setScenario(Element::SCENARIO_LIVE);
    $owner->setFieldValue($field->handle, [F::urlLinkPayload('javascript:alert(1)', 'Unsafe')]);
    expect($owner->getFieldValue($field->handle)->isEmpty())->toBeTrue();
    expect(Craft::$app->elements->saveElement($owner))->toBeFalse();
    expect($owner->getErrors($field->handle . '[0].linkValue'))->not->toBeEmpty();
});

it('recognizes fixed URLs and supported destination schemes without requiring raw link values', function() {
    $fixed = new Url(['handle' => 'fixed', 'isCustom' => true, 'defaultLinkValue' => 'https://example.test/fixed', 'fixedLinkValue' => true]);
    $field = F::hyperFieldWithLinkTypes(array_merge([F::linkTypeConfig($fixed)], array_map(F::linkTypeConfig(...), [
        Url::class, \verbb\hyper\links\Email::class, \verbb\hyper\links\Phone::class,
    ])));
    $links = $field->normalizeValue([
        ['handle' => 'fixed'],
        ['handle' => 'email', 'linkValue' => 'test@example.test'],
        ['handle' => 'phone', 'linkValue' => '0'],
        ['handle' => 'url', 'linkValue' => '#contact'],
        ['handle' => 'url', 'linkValue' => 'javascript:alert(1)', 'linkText' => 'Unsafe'],
    ]);
    expect(array_map(fn($link) => $link->getUrl(), $links->all()))->toBe([
        'https://example.test/fixed', 'mailto:test@example.test', 'tel:0', '#contact',
    ]);
    $fixedLink = $links->first();
    $fixedLink->linkValue = null;
    expect($fixedLink->isEmpty())->toBeFalse();
    expect($links->empty(true)->first()->getCustomLinkText())->toBe('Unsafe');
});

it('disambiguates native attributes from custom fields and rejects relation comparisons', function() {
    $caption = new PlainText(['name' => 'Custom title', 'handle' => F::handle('caption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption, ['handle' => 'type']), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $links = $field->normalizeValue([['handle' => 'url', 'linkValue' => '/a', 'fields' => ['type' => 'Custom type']]]);
    expect($links->where(['type' => Url::class])->count())->toBe(1);
    expect($links->where(['fields.type' => 'Custom type'])->count())->toBe(1);
    expect(fn() => $links->where([$related->handle => 'not a scalar'])->all())->toThrow(InvalidArgumentException::class);
});
