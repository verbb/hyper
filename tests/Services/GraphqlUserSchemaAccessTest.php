<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\fields\Entries as EntriesField;
use craft\helpers\Gql as GqlHelper;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Asset as AssetLink;
use verbb\hyper\links\Entry as EntryLink;

it('limits linked entries and assets to what the user of an authenticated schema can view', function() {
    $hyper = F::hyperField(['linkTypes' => [EntryLink::class, AssetLink::class], 'multipleLinks' => true]);
    $section = F::entrySection($hyper);
    $related = new EntriesField(['name' => 'Related', 'handle' => F::handle('userSchemaEntries'), 'sources' => '*']);
    expect(Craft::$app->fields->saveField($related))->toBeTrue();
    F::attachFieldToSection($section, $related);

    $fsHandle = F::handle('userSchemaFs');
    $path = sys_get_temp_dir() . '/' . $fsHandle;
    $fs = new craft\fs\Local(['name' => $fsHandle, 'handle' => $fsHandle, 'path' => $path, 'hasUrls' => true, 'url' => 'https://example.test/' . $fsHandle]);
    expect(Craft::$app->fs->saveFilesystem($fs))->toBeTrue();
    $volume = new craft\models\Volume(['name' => $fsHandle, 'handle' => $fsHandle, 'fsHandle' => $fsHandle]);
    expect(Craft::$app->volumes->saveVolume($volume))->toBeTrue();
    $files = new AssetsField(['name' => 'Files', 'handle' => F::handle('userSchemaAssets'), 'sources' => ['volume:' . $volume->uid]]);
    expect(Craft::$app->fields->saveField($files))->toBeTrue();
    F::attachFieldToSection($section, $files);

    $originalPermissions = Craft::$app->userPermissions;
    $originalGql = Craft::$app->gql;
    $originalIdentity = Craft::$app->getUser()->getIdentity();
    $admin = User::find()->admin()->one();
    $handle = F::handle('userSchemaViewer');
    $viewer = new User(['username' => $handle, 'email' => $handle . '@example.test', 'pending' => false]);
    expect(Craft::$app->elements->saveElement($viewer))->toBeTrue();
    $paths = [];

    try {
        // Craft caches its permission tree, so newly created sections and volumes need a fresh one.
        Craft::$app->set('userPermissions', new craft\services\UserPermissions());
        $permissions = ['accessCp', 'viewEntries:' . $section->uid, 'viewAssets:' . $volume->uid];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $permissions[] = 'editSite:' . $site->uid;
        }
        expect(Craft::$app->userPermissions->saveUserPermissions($viewer->id, $permissions))->toBeTrue();
        $viewer = User::find()->id($viewer->id)->status(null)->one();

        $entry = function(string $title, User $author, array $values = []) use ($section): Entry {
            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId' => Craft::$app->getEntries()->getEntryTypesBySectionId($section->id)[0]->id,
                'title' => $title,
                'slug' => StringHelper::toKebabCase($title) . '-' . StringHelper::randomString(6),
                'authorId' => $author->id,
                'enabled' => true,
            ]);
            $entry->setFieldValues($values);
            expect(Craft::$app->elements->saveElement($entry))->toBeTrue(json_encode($entry->getErrors()));

            return $entry;
        };
        $asset = function(string $name, User $uploader) use ($volume, &$paths): Asset {
            $paths[] = $temp = tempnam(sys_get_temp_dir(), 'hyper-user-schema-');
            file_put_contents($temp, $name);
            $asset = new Asset([
                'tempFilePath' => $temp,
                'filename' => $name . '.txt',
                'newFolderId' => Craft::$app->assets->getRootFolderByVolumeId($volume->id)->id,
                'volumeId' => $volume->id,
                'uploaderId' => $uploader->id,
            ]);
            $asset->setScenario(Asset::SCENARIO_CREATE);
            expect(Craft::$app->elements->saveElement($asset))->toBeTrue(json_encode($asset->getErrors()));

            return $asset;
        };
        $assetPayload = fn(Asset $asset): array => ['type' => AssetLink::class, 'handle' => AssetLink::typeKey(), 'linkValue' => [$asset->id], 'linkText' => $asset->filename];

        $peer = $entry('Peer target', $admin);
        $own = $entry('Own target', $viewer);
        $peerAsset = $asset('peer-file', $admin);
        $ownAsset = $asset('own-file', $viewer);
        $targets = [$peer, $own, $peerAsset, $ownAsset];
        $source = $entry('Linking source', $viewer, [
            $hyper->handle => array_map(fn($target) => $target instanceof Asset ? $assetPayload($target) : F::entryLinkPayload($target), $targets),
            $related->handle => [$peer->id, $own->id],
            $files->handle => [$peerAsset->id, $ownAsset->id],
        ]);
        $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
        $query = '{ entries(id: ' . $source->id . ') { ... on ' . $type->handle . '_Entry { links: ' . $hyper->handle . ' { element { id } } related: ' . $related->handle . ' { id } files: ' . $files->handle . ' { id } } } }';
        $ids = fn(array $elements): array => array_map(fn($element) => $element ? ['id' => (string)$element->id] : null, $elements);
        $run = function(GqlSchema $schema, ?User $identity) use ($query): array {
            Craft::$app->gql->flushCaches();
            $gql = new craft\services\Gql();
            Craft::$app->set('gql', $gql);
            // Craft's own relation fields read view permissions from the identity, as GraphqlController sets it.
            Craft::$app->getUser()->setIdentity($identity);
            $result = $gql->executeQuery($schema, $query, debugMode: true);
            expect($result['errors'] ?? [])->toBe([], json_encode($result));
            expect($result['data']['entries'])->toHaveCount(1);

            return $result['data']['entries'][0];
        };

        $userSchema = Craft::$app->gql->getSchemaForUser($viewer);
        $asUser = $run($userSchema, $viewer);
        expect($asUser['related'])->toBe($ids([$own]))
            ->and($asUser['files'])->toBe($ids([$ownAsset]))
            ->and(array_map(fn($link) => $link['element'] ?? null, $asUser['links']))
            ->toBe($ids([null, $own, null, $ownAsset]))
            ->and($asUser['links'][0])->toBeNull()
            ->and($asUser['links'][2])->toBeNull();

        // A token schema with the same scope has no user, so its results stay as they were.
        $tokenSchema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Same scope token', 'scope' => $userSchema->scope]);
        $asToken = $run($tokenSchema, null);
        expect($asToken['related'])->toBe($ids([$peer, $own]))
            ->and($asToken['files'])->toBe($ids([$peerAsset, $ownAsset]))
            ->and(array_map(fn($link) => $link['element'] ?? null, $asToken['links']))->toBe($ids($targets));
    } finally {
        Craft::$app->getUser()->setIdentity($originalIdentity);
        Craft::$app->gql->flushCaches();
        Craft::$app->set('gql', $originalGql);
        $originalGql->flushCaches();
        Craft::$app->set('userPermissions', $originalPermissions);
        foreach (Asset::find()->volumeId($volume->id)->status(null)->all() as $file) {
            Craft::$app->elements->deleteElement($file, true);
        }
        Craft::$app->elements->deleteElement($viewer, true);
        Craft::$app->fields->deleteField($files);
        Craft::$app->volumes->deleteVolume($volume);
        Craft::$app->fs->removeFilesystem($fs);
        foreach ($paths as $temp) {
            @unlink($temp);
        }
    }
})->skip(fn() => !method_exists(GqlHelper::class, 'getSchemaUser'), 'User-authenticated GraphQL schemas need Craft 5.12 or later.');
