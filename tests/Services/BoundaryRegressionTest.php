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
it('bounds self links and cycles while priming later owners', function () {
    $r = Hyper::$plugin->linkRelations;
    $r->enableRequestPriming = false;
    $f = F::hyperField();
    $s = F::entrySection($f);
    $a = F::plainEntry($s, 'A');
    $b = F::plainEntry($s, 'B');
    $c = F::plainEntry($s, 'C');
    foreach ([[$a, $b], [$b, $a], [$c, $c]] as [$owner, $target]) {
        $owner->setFieldValue($f->handle, [['handle' => 'entry', 'linkValue' => [$target->id]]]);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    }
    $r->resetRequestState();
    $r->enableRequestPriming = true;
    $visits = 0;
    $guard = function () use (&$visits) {
        if (++$visits > 20) {
            throw new RuntimeException('Unbounded priming');
        }
    };
    Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, $guard);
    try {
        Entry::find()->id($a->id)->one();
        expect($r->getPrimedElement($b->id, $b->siteId)?->id)->toBe($b->id);
        Entry::find()->id($c->id)->one();
        expect($r->getPrimedElement($c->id, $c->siteId)?->id)->toBe($c->id);
        expect($visits)->toBeLessThanOrEqual(4);
    } finally {
        Event::off(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, $guard);
    }
});

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

it('adopts one-sided and pre-serialized legacy UIDs without losing translations', function () {
    $f = F::hyperField(['multipleLinks' => true, 'linkTypes' => [Url::class]]);
    foreach ([[null, 'known'], ['known', null], [null, null]] as [$sourceUid, $targetUid]) {
        $mk = fn($uid, $text) => ['handle' => 'url', 'uid' => $uid, 'linkValue' => 'https://example.test', 'linkText' => $text];
        $source = new LinkCollection($f, [$mk($sourceUid, 'Source')]);
        $target = new LinkCollection($f, [$mk($targetUid, 'Translated')]);
        $source->serializeValues();
        $merged = Hyper::$plugin->multisiteLinks->mergeStructuralLinks($f, $source, $target, 1, 2)->serializeValues();
        expect($merged[0]['linkText'])->toBe('Translated');
        expect($merged[0]['uid'])->toBe($source->getLinks()[0]->uid);
    }
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
