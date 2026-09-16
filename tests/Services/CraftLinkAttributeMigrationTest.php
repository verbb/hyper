<?php

use craft\db\Query;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\MigrateCraftLinkContent;
use verbb\hyper\migrations\MigrateCraftLinkField;

it('preserves native link IDs relations and downloads through migration and resave', function() {
    $source = new craft\fields\Link([
        'name' => 'Native advanced link', 'handle' => F::handle('nativeAdvanced'), 'types' => ['url'],
        'showLabelField' => true, 'advancedFields' => ['id', 'rel', 'download'],
    ]);
    expect(Craft::$app->fields->saveField($source))->toBeTrue();
    $section = F::entrySectionWithField($source);
    $owners = [];
    foreach ([[true, null], [true, 'report.pdf'], [false, 'unused.pdf']] as [$download, $filename]) {
        $owner = F::plainEntry($section, 'Native download', [$source->handle => [
            'type' => 'url', 'value' => 'https://example.test/file', 'label' => 'Download',
            'id' => 'resource', 'rel' => 'nofollow', 'download' => $download, 'filename' => $filename,
        ]]);
        expect($owner->getFieldValue($source->handle)->getAttributes()['id'])->toBe('resource');
        $owners[$owner->id] = $download ? ($filename ?? true) : null;
    }
    $read = fn() => (new Query())->select(['elementId', 'content'])->from('{{%elements_sites}}')->where(['elementId' => array_keys($owners)])->orderBy('elementId')->all();
    $fieldResult = Hyper::$plugin->createMigrator(MigrateCraftLinkField::class)->run();
    expect($fieldResult->ok)->toBeTrue();
    $before = $read();
    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class, ['dryRun' => true])->run()->ok)->toBeTrue();
    expect($read())->toBe($before);
    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class)->run()->ok)->toBeTrue();
    $converted = $read();
    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class)->run()->ok)->toBeTrue();
    expect($read())->toBe($converted);
    // Migration steps and subsequent editing normally run in separate requests.
    // Reload Craft's actual field/layout service so old native layouts are not cached.
    Craft::$app->set('fields', new \craft\services\Fields());
    Craft::$app->set('entries', new \craft\services\Entries());
    foreach ($owners as $id => $download) {
        $owner = Entry::find()->id($id)->one();
        for ($pass = 0; $pass < 2; $pass++) {
            $link = $owner->getFieldValue($source->handle)->first();
            $attributes = $link->getLinkAttributes();
            expect($attributes['id'] ?? null)->toBe('resource');
            expect($attributes['rel'] ?? null)->toBe('nofollow');
            expect($attributes['download'] ?? null)->toBe($download);
            $dom = new DOMDocument();
            @$dom->loadHTML((string)$link->getLink());
            expect($dom->getElementsByTagName('a')[0]->hasAttribute('download'))->toBe($download !== null);
            // Submit the values emitted by Craft's real editable-table controls.
            $input = new DOMDocument();
            @$input->loadHTML($link->getFieldLayout()->getField('customAttributes')->inputHtml($link));
            $posted = [];
            foreach ($input->getElementsByTagName('textarea') as $cell) {
                $posted[] = urlencode($cell->getAttribute('name')) . '=' . urlencode($cell->textContent);
            }
            parse_str(implode('&', $posted), $data);
            expect($data)->toHaveKey('customAttributes');
            $link->customAttributes = $data['customAttributes'];
            $owner->setFieldValue($source->handle, $owner->getFieldValue($source->handle));
            expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
            $owner = Entry::find()->id($id)->one();
        }
    }
});
