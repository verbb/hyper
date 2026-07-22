<?php

declare(strict_types=1);

use craft\elements\conditions\TitleConditionRule;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;
use verbb\hyper\links\Category;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

it('exposes selectable condition support for entry link types', function() {
    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);

    expect($link->supportsSelectionCondition())->toBeTrue();
});

it('does not expose selectable conditions for category link types', function() {
    $link = Hyper::$plugin->getLinks()->createLink(Category::class);

    expect($link->supportsSelectionCondition())->toBeFalse();
});

it('persists selection condition config on entry link type settings', function() {
    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);
    $link->handle = 'default-' . StringHelper::toKebabCase(EntryLink::class);
    $link->label = EntryLink::displayName();
    $link->enabled = true;
    $link->sources = '*';
    $link->layoutUid = StringHelper::UUID();
    $link->layoutConfig = EntryLink::getDefaultFieldLayout()->getConfig();

    $condition = Entry::createCondition();
    $rule = $condition->createConditionRule([
        'class' => TitleConditionRule::class,
        'operator' => '**',
        'value' => 'Hyper Condition',
    ]);
    $condition->setConditionRules([$rule]);
    $link->setSelectionCondition($condition);

    $config = $link->getSettingsConfig();
    expect($config)->toHaveKey('selectionCondition');
    expect($config['selectionCondition']['conditionRules'] ?? [])->not->toBeEmpty();

    $field = HyperFixtureFactory::hyperFieldWithLinkTypes([
        HyperFixtureFactory::linkTypeConfig(Url::class),
        $link->getSettingsConfigForDb(),
    ]);

    $entryType = null;
    foreach ($field->getLinkTypes() as $type) {
        if ($type instanceof EntryLink) {
            $entryType = $type;
            break;
        }
    }

    expect($entryType)->not->toBeNull();
    expect($entryType->getSelectionCondition())->not->toBeNull();
    expect($entryType->getSelectionConditionConfig())->toHaveKey('conditionRules');
});

it('exposes selection condition config for bulk-add element modals', function() {
    $link = Hyper::$plugin->getLinks()->createLink(EntryLink::class);
    $condition = Entry::createCondition();
    $rule = $condition->createConditionRule([
        'class' => TitleConditionRule::class,
        'operator' => '**',
        'value' => 'Hyper Condition',
    ]);
    $condition->setConditionRules([$rule]);
    $link->setSelectionCondition($condition);

    $config = $link->getSelectionConditionConfig();
    expect($config)->not->toBeNull();
    expect($config['conditionRules'] ?? [])->not->toBeEmpty();
    expect($config['class'] ?? null)->toBe(get_class($condition));
});
