<?php

use craft\elements\User;
use craft\fields\PlainText;
use craft\fields\Users;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Entry as EntryLink;

it('does not expose linked elements from sections outside the active schema', function() {
    $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
    $public = F::entrySection($field);
    $private = F::entrySection();
    $type = Craft::$app->entries->getEntryTypesBySectionId($public->id)[0];
    // A shared entry type keeps the target's GraphQL type available in both sections.
    $private->setEntryTypes([$type]);
    expect(Craft::$app->entries->saveSection($private))->toBeTrue();
    $target = F::plainEntry($private, 'Private destination');
    $owner = F::plainEntry($public, 'Public owner', [$field->handle => [F::entryLinkPayload($target)]]);
    $gql = new \craft\services\Gql();
    $original = Craft::$app->gql;
    $original->flushCaches();
    Craft::$app->set('gql', $gql);
    try {
        foreach ([false, true] as $allowPrivate) {
            $gql->flushCaches();
            $gql = new \craft\services\Gql();
            Craft::$app->set('gql', $gql);
            $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Section boundary', 'scope' => [
                'sections.' . $public->uid . ':read', ...($allowPrivate ? ['sections.' . $private->uid . ':read'] : []),
            ]]);
            $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . ' { element { id } } } } }', debugMode: true);
            expect($result['errors'] ?? [])->toBe([], json_encode($result));
            expect($result['data']['entries'][0][$field->handle][0]['element'])->toBe($allowPrivate ? ['id' => (string)$target->id] : null);
        }
    } finally {
        $gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
    }
});

it('restricts linked users by schema group while allowing explicit everyone access', function() {
    $group = new \craft\models\UserGroup(['name' => 'API group', 'handle' => F::handle('apiGroup')]);
    expect(Craft::$app->userGroups->saveGroup($group))->toBeTrue();
    $field = F::hyperField(['linkTypes' => [\verbb\hyper\links\User::class]]);
    $section = F::entrySection($field);
    $person = User::find()->admin()->one();
    $owner = F::plainEntry($section, 'User link owner', [$field->handle => [['handle' => 'user', 'linkValue' => [$person->id]]]]);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $original = Craft::$app->gql;
    try {
        foreach ([$group->uid, 'everyone'] as $groupUid) {
            Craft::$app->gql->flushCaches();
            $gql = new \craft\services\Gql();
            Craft::$app->set('gql', $gql);
            $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'User boundary', 'scope' => [
                'sections.' . $section->uid . ':read', 'usergroups.' . $groupUid . ':read',
            ]]);
            $result = $gql->executeQuery($schema, '{ entries(id: ' . $owner->id . ') { ... on ' . $type->handle . '_Entry { ' . $field->handle . ' { element { id } } } } }', debugMode: true);
            expect($result['errors'] ?? [])->toBe([], json_encode($result));
            expect($result['data']['entries'][0][$field->handle][0]['element'])->toBe($groupUid === 'everyone' ? ['id' => (string)$person->id] : null);
        }
    } finally {
        Craft::$app->gql->flushCaches();
        Craft::$app->set('gql', $original);
        $original->flushCaches();
        Craft::$app->userGroups->deleteGroup($group);
    }
});

it('executes link fragments and enforces schema restrictions in both typed fields and the JSON bag', function() {
    $text = new PlainText(['name' => 'Public caption', 'handle' => F::handle('publicCaption')]);
    $users = new Users(['name' => 'Restricted people', 'handle' => F::handle('restrictedPeople')]);
    expect(Craft::$app->fields->saveField($text))->toBeTrue();
    expect(Craft::$app->fields->saveField($users))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($text), new CustomField($users)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(EntryLink::class), F::linkTypeConfig(Embed::class)], ['multipleLinks' => true]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Destination');
    $person = User::find()->admin()->one();
    $metadata = ['url' => 'https://video.example.test/watch', 'code' => '<iframe src="https://video.example.test/embed"></iframe>', 'providerName' => 'Example Video', 'image' => 'https://video.example.test/thumb.jpg'];
    $owner = F::plainEntry($section, 'API owner', [$field->handle => [
        ['handle' => 'url', 'linkValue' => 'https://example.test/url', 'linkText' => 'URL caption', 'fields' => [$text->handle => 'Public value', $users->handle => [$person->id]]],
        F::entryLinkPayload($target, 'Entry caption'),
        ['handle' => 'embed', 'linkValue' => $metadata, 'linkText' => 'Video caption'],
    ]]);
    $empty = F::plainEntry($section, 'Empty owner', [$field->handle => []]);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $entryType = $type->handle . '_Entry';
    $linkType = $field->handle . '_Url_LinkType';
    $queryHandle = $field->handle;
    $gql = Craft::$app->gql;
    $run = function(bool $allowUsers, string $selection, int $id, bool $debug = false) use ($gql, $section, $entryType, &$queryHandle) {
        // Each query represents a new request. Craft's field-argument/type-manager caches
        // outlive flushCaches(), so use a fresh real service alongside fresh registries.
        $gql->flushCaches();
        $gql = new \craft\services\Gql();
        Craft::$app->set('gql', $gql);
        $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Hyper contract', 'scope' => [
            'sections.' . $section->uid . ':read',
            ...($allowUsers ? ['usergroups.everyone:read'] : []),
        ]]);
        return $gql->executeQuery($schema, '{ entries(id: ' . $id . ') { ... on ' . $entryType . ' { ' . $queryHandle . ' { ' . $selection . ' } } } }', debugMode: $debug);
    };
    $selection = '__typename url text fields html iframeSrc providerName embedImage linkValue ... on ' . $linkType . ' { ' . $text->handle . ' }';
    try {
        foreach ([true, false] as $allowUsers) {
            $result = $run($allowUsers, $selection, $owner->id);
            expect($result['errors'] ?? [])->toBe([], json_encode($result));
            $links = $result['data']['entries'][0][$field->handle];
            expect($links)->toHaveCount(3);
            expect(array_column($links, 'url'))->toBe(['https://example.test/url', $target->getUrl(), $metadata['url']]);
            expect(array_column($links, 'text'))->toBe(['URL caption', 'Entry caption', 'Video caption']);
            expect($links[0]['__typename'])->toBe($linkType);
            expect($links[0][$text->handle])->toBe('Public value');
            expect(json_decode($links[0]['fields'], true))->toBe([
                $text->handle => 'Public value', ...($allowUsers ? [$users->handle => [$person->id]] : []),
            ]);
            expect($links[0]['html'])->toBeNull();
            expect($links[2]['html'])->toBe($metadata['code']);
            expect($links[2]['iframeSrc'])->toBe('https://video.example.test/embed');
            expect($links[2]['providerName'])->toBe($metadata['providerName']);
            expect($links[2]['embedImage'])->toBe($metadata['image']);
            expect(json_decode($links[2]['linkValue'], true))->toEqual($metadata);
        }

        $typedUsers = '... on ' . $linkType . ' { ' . $users->handle . ' { id } }';
        $allowed = $run(true, $typedUsers, $owner->id);
        expect($allowed['errors'] ?? [])->toBe([], json_encode($allowed));
        expect($allowed['data']['entries'][0][$field->handle][0][$users->handle])->toBe([['id' => (string)$person->id]]);
        $denied = $run(false, $typedUsers, $owner->id, true);
        expect($denied['data'] ?? null)->toBeNull(json_encode($denied));
        expect($denied['errors'])->toHaveCount(1);
        expect($denied['errors'][0]['message'])->toContain('Cannot query field "' . $users->handle . '"');

        $emptyResult = $run(false, 'url', $empty->id);
        expect($emptyResult['errors'] ?? [])->toBe([]);
        expect($emptyResult['data']['entries'][0][$field->handle])->toBe([]);

        $configs = $field->getSettings()['linkTypes'];
        $configs[0]['label'] = 'Lien modifié';
        $field->setLinkTypes($configs);
        expect(Craft::$app->fields->saveField($field))->toBeTrue();
        $renamed = $run(false, '__typename ... on ' . $linkType . ' { ' . $text->handle . ' }', $owner->id);
        expect($renamed['errors'] ?? [])->toBe([], json_encode($renamed));
        expect($renamed['data']['entries'][0][$field->handle][0])->toBe(['__typename' => $linkType, $text->handle => 'Public value']);

        // A layout alias changes the query key, while its concrete type stays tied to the source field.
        foreach ($type->getFieldLayout()->getCustomFieldElements() as $element) {
            if ($element->getField()->uid === $field->uid) {
                $element->handle = 'apiLinks';
                $element->setField($field);
            }
        }
        $type->getFieldLayout()->setTabs($type->getFieldLayout()->getTabs());
        expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
        Craft::$app->entries->refreshEntryTypes();
        expect(array_map(fn($element) => $element->getField()->handle, Craft::$app->entries->getEntryTypeById($type->id)->getFieldLayout()->getCustomFieldElements()))->toContain('apiLinks');
        $queryHandle = 'apiLinks';
        $aliased = $run(false, '__typename ... on ' . $linkType . ' { ' . $text->handle . ' }', $owner->id, true);
        expect($aliased['errors'] ?? [])->toBe([], json_encode($aliased));
        expect($aliased['data']['entries'][0]['apiLinks'][0])->toBe(['__typename' => $linkType, $text->handle => 'Public value']);

        $field->multipleLinks = false;
        expect(Craft::$app->fields->saveField($field))->toBeTrue();
        $single = F::plainEntry($section, 'Single link owner', ['apiLinks' => [['handle' => 'url', 'linkValue' => 'https://example.test/single']]]);
        $singleResult = $run(false, 'url', $single->id);
        expect($singleResult['errors'] ?? [])->toBe([], json_encode($singleResult));
        expect($singleResult['data']['entries'][0]['apiLinks'])->toBe([['url' => 'https://example.test/single']]);

    } finally {
        Craft::$app->gql->flushCaches();
        Craft::$app->set('gql', $gql);
        $gql->flushCaches();
        Craft::$app->fields->deleteField($users);
        Craft::$app->fields->deleteField($text);
    }
});
