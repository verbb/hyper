<?php

declare(strict_types=1);

use craft\events\CreateFieldLayoutFormEvent;
use craft\models\FieldLayout;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\base\Link;
use yii\base\Event;

it('provides the Hyper field owner to every transient Link layout render path', function() {
    $field = F::hyperField(['multipleLinks' => true]);
    $owner = F::plainEntry(F::entrySection($field), 'Link layout owner');
    $links = $field->normalizeValue([
        F::urlLinkPayload('https://example.test/existing', 'Existing'),
    ], $owner);

    $captured = [];
    $handler = function(CreateFieldLayoutFormEvent $event) use (&$captured): void {
        if ($event->element instanceof Link) {
            $captured[] = $event->element;
        }
    };
    Event::on(FieldLayout::class, FieldLayout::EVENT_CREATE_FORM, $handler);

    try {
        (new ReflectionMethod($field, '_getLinkTypeInfoForInput'))->invoke($field, $owner, 'owner-context');
        (new ReflectionMethod($field, '_getLinksForInput'))->invoke($field, $links, 'owner-context', $owner);
        $field->getHydratedLinkBlocks(
            $field->defaultLinkType,
            [F::urlLinkPayload('https://example.test/hydrated', 'Hydrated')],
            $owner,
            false,
        );
    } finally {
        Event::off(FieldLayout::class, FieldLayout::EVENT_CREATE_FORM, $handler);
    }

    expect($captured)->not->toBeEmpty();

    foreach ($captured as $link) {
        expect($link->getOwner())->toBe($owner)
            ->and($link->siteId)->toBe($owner->siteId);
    }
});
