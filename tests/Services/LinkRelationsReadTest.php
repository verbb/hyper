<?php

declare(strict_types=1);

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\Hyper;

it('uses batch-primed elements when resolving entry links after a query', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target')],
        'Target entry',
    );
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, 'Points at target')],
        'Owner entry',
    );

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $entries = Entry::find()->section($section->handle)->id($owner->id)->all();
    $links = $entries[0]->getFieldValue($field->handle);
    $link = $links->getLinks()[0];

    expect($link->getElement())->not->toBeNull();
    expect($link->getElement()->id)->toBe($target->id);
});

it('resolves entry link url and text without hyper_element_cache rows', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target-page')],
        'Target entry',
    );
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, 'Custom nav label')],
        'Owner entry',
    );

    $db = Craft::$app->getDb();

    if ($db->tableExists('{{%hyper_element_cache}}')) {
        $db->createCommand()
            ->delete('{{%hyper_element_cache}}', [
                'sourceId' => $owner->id,
                'fieldId' => $field->id,
            ])
            ->execute();

        expect((int)(new \craft\db\Query())
            ->from('{{%hyper_element_cache}}')
            ->where(['sourceId' => $owner->id, 'fieldId' => $field->id])
            ->count())->toBe(0);
    }

    Hyper::$plugin->getLinkRelations()->resetRequestState();

    $entries = Entry::find()->section($section->handle)->id($owner->id)->all();
    $link = $entries[0]->getFieldValue($field->handle)->getLinks()[0];

    expect($link->getUrl())->not->toBeNull();
    expect($link->getLinkText())->toBe('Custom nav label');
});
