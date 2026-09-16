<?php

use craft\elements\Entry;
use craft\elements\db\ElementQuery;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\records\LinkRelation;
use yii\base\Event;
it('validates author destinations but preserves trusted template overrides', function () {
    $f = F::hyperField(['linkTypes' => [Url::class]]);
    $l = Hyper::$plugin->links->createLinkFromSerialized($f, ['handle' => 'url', 'linkValue' => 'https://example.test', 'linkText' => 'Safe']);
    foreach (['href', ' HREF ', 'xlink:href'] as $name) {
        $l->customAttributes = [['attribute' => $name, 'value' => 'javascript:void(0)']];
        expect((string) $l->getLink())->not->toContain('javascript:');
    }
    foreach (["java\nscript:void(0)", "java\tscript:void(0)", "\x01javascript:void(0)"] as $value) {
        $l->linkValue = $value;
        expect($l->getUrl())->toBeNull();
    }
    expect($l->getLinkAttributes(['href' => 'slack://trusted'])['href'])->toBe('slack://trusted');
});

it('retains configured missing classes through input and object rebinding', function () {
    $f = F::hyperFieldWithLinkTypes([['type' => 'synthetic\MissingType', 'handle' => 'gone', 'label' => 'Gone', 'enabled' => true]]);
    $raw = ['linkTypeHandle' => 'gone', 'uid' => 'opaque-record', 'linkValue' => 'https://example.test', 'fields' => ['keep' => '00123', 'empty' => []], 'extension' => ['empty' => null]];
    $c = $f->normalizeValue([$raw]);
    expect($f->serializeValue($c)[0])->toBe($raw);
    expect($c->getLinks()[0]->getInputConfig()['unsupportedPayload'])->toBe($raw);
    expect($f->serializeValue(new LinkCollection($f, $c->getLinks()))[0])->toBe($raw);
});

it('preserves unavailable custom fields and does not reinterpret orphaned shared configs', function () {
    $f = F::hyperField(['linkTypes' => [Url::class]]);
    $raw = ['handle' => 'url', 'linkValue' => 'https://example.test', 'fields' => ['unavailable' => '00123']];
    expect($f->serializeValue($f->normalizeValue([$raw]))[0]['fields'])->toBe(['unavailable' => '00123']);
    $f->useLinkTypeConfig('missing-shared-config');
    expect($f->getLinkTypes())->toBeEmpty();
    $link = $f->normalizeValue([$raw])->getLinks()[0];
    expect($link)->toBeInstanceOf(\verbb\hyper\links\MissingLink::class);
    expect($link->getSerializedValues()['fields'])->toBe(['unavailable' => '00123']);
});
