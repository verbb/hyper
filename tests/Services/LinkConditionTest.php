<?php

declare(strict_types=1);

use craft\elements\conditions\DateCreatedConditionRule;
use craft\elements\conditions\RelatedToConditionRule;
use craft\elements\conditions\SlugConditionRule;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\elements\conditions\ElementLinkCondition;
use verbb\hyper\elements\conditions\LinkCondition;
use verbb\hyper\elements\conditions\LinkValueConditionRule;
use verbb\hyper\Hyper;
use verbb\hyper\links\Email;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

it('uses LinkCondition for hyper link field layouts', function() {
    expect(Url::createCondition())->toBeInstanceOf(LinkCondition::class);
    expect(Email::createCondition())->toBeInstanceOf(LinkCondition::class);
});

it('offers link value without craft element attribute rules', function() {
    $condition = Url::createCondition();
    $condition->setFieldLayouts([Url::getDefaultFieldLayout()]);

    $ref = new \ReflectionClass($condition);
    $method = $ref->getMethod('selectableConditionRules');
    $method->setAccessible(true);
    /** @var list<class-string|array{class: class-string}> $raw */
    $raw = $method->invoke($condition);

    $selectable = array_map(
        static fn($type) => is_string($type) ? $type : ($type['class'] ?? null),
        $raw
    );

    expect($selectable)->toContain(LinkValueConditionRule::class);
    expect($selectable)->not->toContain(SlugConditionRule::class);
    expect($selectable)->not->toContain(DateCreatedConditionRule::class);
    expect($selectable)->not->toContain(RelatedToConditionRule::class);
});

it('matches link value text rules on url links', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Url::class);
    $link->linkValue = 'https://hyper-react.test/docs';

    $condition = Url::createCondition();
    $rule = $condition->createConditionRule([
        'class' => LinkValueConditionRule::class,
        'operator' => '**',
        'value' => 'docs',
    ]);
    $condition->setConditionRules([$rule]);

    expect($condition->matchElement($link))->toBeTrue();

    $link->linkValue = 'https://hyper-react.test/about';
    expect($condition->matchElement($link))->toBeFalse();
});

it('matches link value text rules on email links', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Email::class);
    $link->linkValue = 'hello@example.com';

    $condition = Email::createCondition();
    $rule = $condition->createConditionRule([
        'class' => LinkValueConditionRule::class,
        'operator' => '=',
        'value' => 'hello@example.com',
    ]);
    $condition->setConditionRules([$rule]);

    expect($condition->matchElement($link))->toBeTrue();
});

it('uses ElementLinkCondition for entry link field layouts', function() {
    expect(EntryLink::createCondition())->toBeInstanceOf(ElementLinkCondition::class);
});

it('matches entry slug rules against the linked entry, not the hyper link', function() {
    $section = HyperFixtureFactory::entrySection();
    $entryType = $section->getEntryTypes()[0];

    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $entryType->id,
        'title' => 'Condition Target',
        'slug' => 'slug-contains-test-value',
    ]);
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);
    $link->linkValue = $entry->id;
    $link->linkSiteId = $entry->siteId;

    $condition = EntryLink::createCondition();
    $rule = $condition->createConditionRule([
        'class' => SlugConditionRule::class,
        'operator' => '**',
        'value' => 'test',
    ]);
    $condition->setConditionRules([$rule]);

    expect($condition->matchElement($link))->toBeTrue();

    $entry->slug = 'no-match-here';
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    // Fresh link instance so getElement() does not reuse a stale primed target.
    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);
    $link->linkValue = $entry->id;
    $link->linkSiteId = $entry->siteId;

    expect($condition->matchElement($link))->toBeFalse();
});
