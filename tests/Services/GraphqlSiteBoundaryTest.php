<?php

use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;

it('respects explicit schema site restrictions on linked elements', function() {
    [$site, $other] = F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [\verbb\hyper\links\Entry::class]]);
    $section = F::translatableEntrySection($field, 2);
    $target = F::plainEntry($section, 'Translated destination');
    $translated = Entry::find()->id($target->id)->siteId($other->id)->one();
    expect($translated)->not->toBeNull();
    $payload = F::entryLinkPayload($translated);
    $payload['linkSiteId'] = $other->id;
    $owner = F::plainEntry($section, 'Public site owner', [$field->handle => [$payload]]);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $original = Craft::$app->gql;
    try {
        foreach ([false, true] as $allowOther) {
            Craft::$app->gql->flushCaches();
            $gql = new \craft\services\Gql();
            Craft::$app->set('gql', $gql);
            $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Site boundary', 'scope' => [
                'sections.' . $section->uid . ':read', 'sites.' . $site->uid . ':read',
                ...($allowOther ? ['sites.' . $other->uid . ':read'] : []),
            ]]);
            $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ', siteId: ' . $site->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . ' { element { id } } } } }', debugMode: true);
            expect($result['errors'] ?? [])->toBe([], json_encode($result));
            expect($result['data']['entries'][0][$field->handle][0]['element'])->toBe($allowOther ? ['id' => (string)$target->id] : null);
        }
    } finally {
        Craft::$app->gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
});
