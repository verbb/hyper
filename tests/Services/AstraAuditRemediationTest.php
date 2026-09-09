<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;
use verbb\hyper\links\User as UserLink;
use verbb\hyper\models\LinkCollection;

it('canonicalizes legacy v2 handles on hydrate and serialize', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $legacy = 'default-' . \craft\helpers\StringHelper::toKebabCase(Url::class);

    $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, [
        'handle' => $legacy,
        'type' => Url::class,
        'linkValue' => 'https://example.test/canonical',
        'linkText' => 'Canonical',
    ]);

    expect($link)->not->toBeNull();
    expect($link->handle)->toBe('url');
    expect($link->getSerializedValues()['linkTypeHandle'] ?? null)->toBe('url');
});

it('retains unknown link type content through normalize and serialize', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $collection = new LinkCollection($field, [[
        'linkTypeHandle' => 'temporarilyMissing',
        'linkValue' => 'https://example.test/keep',
        'linkText' => 'Keep me',
        'fields' => ['saved' => 'yes'],
        'uid' => 'opaque-uid-1',
    ]]);

    $serialized = $field->serializeValue($collection);

    expect($serialized)->toHaveCount(1);
    expect($serialized[0]['linkTypeHandle'] ?? null)->toBe('temporarilyMissing');
    expect($serialized[0]['linkValue'] ?? null)->toBe('https://example.test/keep');
    expect($serialized[0]['linkText'] ?? null)->toBe('Keep me');
});

it('merges multisite translations by uid not position', function() {
    $field = HyperFixtureFactory::hyperField(['multipleLinks' => true, 'linkTypes' => [Url::class]]);
    $mk = fn(string $uid, string $value, string $text) => [
        'linkTypeHandle' => 'url',
        'uid' => $uid,
        'linkValue' => $value,
        'linkText' => $text,
    ];

    $source = new LinkCollection($field, [
        $mk('b', 'https://example.test/b', 'B'),
        $mk('a', 'https://example.test/a', 'A'),
    ]);
    $target = new LinkCollection($field, [
        $mk('a', 'https://example.test/a', 'A translated'),
        $mk('b', 'https://example.test/b', 'B translated'),
    ]);

    $merged = Hyper::$plugin->getMultisiteLinks()->mergeStructuralLinks($field, $source, $target, 1, 2);
    $serialized = $merged->serializeValues();

    expect($serialized[0]['uid'] ?? null)->toBe('b');
    expect($serialized[0]['linkText'] ?? null)->toBe('B translated');
    expect($serialized[1]['uid'] ?? null)->toBe('a');
    expect($serialized[1]['linkText'] ?? null)->toBe('A translated');
});

it('syncs relation index after content modify with site-aware owner resolution', function() {
    $f = HyperFixtureFactory::hyperField();
    $s = HyperFixtureFactory::entrySection($f);
    $one = HyperFixtureFactory::plainEntry($s, 'Target one');
    $two = HyperFixtureFactory::plainEntry($s, 'Target two');
    $owner = HyperFixtureFactory::plainEntry($s, 'Sync owner', [
        $f->handle => [['handle' => 'entry', 'linkValue' => [$one->id]]],
    ]);

    $transform = fn() => new LinkCollection($f, [[
        'handle' => 'entry',
        'uid' => 'audit-target',
        'linkValue' => [$two->id],
    ]]);

    $options = new \verbb\hyper\content\ModifyOptions(elementIds: [$owner->id]);
    $result = Hyper::$plugin->getContent()->modify($f, $transform, $options);

    expect($result->getChangedCount(false))->toBeGreaterThan(0);

    $rows = (new \craft\db\Query())
        ->select(['targetId'])
        ->from(['{{%hyper_links}}'])
        ->where(['ownerId' => $owner->id, 'fieldId' => $f->id])
        ->column();

    expect(array_map('intval', $rows))->toContain($two->id);
});

it('resolves reverse relations when owner and target types differ', function() {
    $field = HyperFixtureFactory::hyperField([
        'linkTypes' => [Url::class, EntryLink::class, UserLink::class],
    ]);
    $section = HyperFixtureFactory::entrySection($field);
    $user = User::find()->status(null)->one();

    expect($user)->not->toBeNull();

    $owner = HyperFixtureFactory::plainEntry($section, 'Entry to user owner', [
        $field->handle => [[
            'handle' => 'user',
            'linkValue' => [$user->id],
            'linkSiteId' => $user->siteId,
        ]],
    ]);

    $collection = $owner->getFieldValue($field->handle);
    Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($field, $owner, $collection);

    $related = Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'elementType' => Entry::class,
        'targetType' => User::class,
        'relatedTo' => [
            'field' => $field->handle,
            'targetElement' => $user,
        ],
    ])?->ids();

    expect($related)->toContain($owner->id);
});

it('rebinds bare Url objects onto the destination field layout', function() {
    $field = HyperFixtureFactory::hyperField(['linkTypes' => [Url::class]]);
    $section = HyperFixtureFactory::entrySection($field);

    $link = new Url();
    $link->linkValue = 'https://example.test/docs';
    $link->linkText = 'Documented';
    $link->fields = ['nonexistentCustom' => 'x'];

    $owner = HyperFixtureFactory::plainEntry($section, 'Object example', [
        $field->handle => [$link],
    ]);

    $reload = Entry::find()->id($owner->id)->status(null)->one();
    $serialized = $field->serializeValue($reload->getFieldValue($field->handle));

    expect($serialized[0]['linkValue'] ?? null)->toBe('https://example.test/docs');
    expect($serialized[0]['linkText'] ?? null)->toBe('Documented');
});

it('parses matrix nested hyper with paths using craft 5 entry types', function() {
    $nested = HyperFixtureFactory::matrixFieldWithHyper();
    $matrix = $nested['matrix'];
    $hyper = $nested['hyperField'];
    $query = Entry::find()->with([$matrix->handle . '.' . $hyper->handle . '.linkedElements']);

    Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);

    expect($query->with)->not->toContain($matrix->handle . '.' . $hyper->handle . '.linkedElements');
});
