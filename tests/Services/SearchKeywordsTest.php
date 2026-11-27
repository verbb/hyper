<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;
use verbb\hyper\links\Email;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Phone;
use verbb\hyper\links\Url;

function hyperSearchTokens(string $keywords): array
{
    return preg_split('/[^a-z0-9]+/', strtolower($keywords), -1, PREG_SPLIT_NO_EMPTY);
}

function hyperSearchableField(array $linkTypeConfigs): \verbb\hyper\fields\HyperField
{
    $field = F::hyperFieldWithLinkTypes($linkTypeConfigs, ['multipleLinks' => true]);
    $field->searchable = true;
    expect(Craft::$app->getFields()->saveField($field))->toBeTrue();

    return Craft::$app->getFields()->getFieldById($field->id);
}

// Search keywords hold what editors typed or searched for, not the stored payload's internals.
it('indexes link text, values and searchable layout fields without internal link data', function() {
    $caption = new PlainText(['name' => 'Caption', 'handle' => F::handle('hyperCaption'), 'searchable' => true]);
    $note = new PlainText(['name' => 'Note', 'handle' => F::handle('hyperNote'), 'searchable' => false]);
    $inner = F::hyperField(['handle' => F::handle('hyperNestedLinks')]);
    $inner->searchable = true;
    expect(Craft::$app->getFields()->saveField($inner))->toBeTrue();
    ['matrix' => $matrix, 'blockEntryType' => $blockType] = F::matrixFieldWithHyper($inner);

    foreach ([$caption, $note] as $custom) {
        expect(Craft::$app->getFields()->saveField($custom))->toBeTrue();
    }

    $matrix->searchable = true;
    expect(Craft::$app->getFields()->saveField($matrix))->toBeTrue();

    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption), new CustomField($note), new CustomField($matrix)]);
    $url->setFieldLayout($layout);

    $field = hyperSearchableField([
        F::linkTypeConfig($url),
        F::linkTypeConfig(Email::class),
        F::linkTypeConfig(Phone::class),
        F::linkTypeConfig(EntryLink::class),
        F::linkTypeConfig(Embed::class),
    ]);
    $target = F::plainEntry(F::entrySection(), 'Search target');
    $owner = F::plainEntry(F::entrySection($field), 'Search owner', [
        $field->handle => [
            [...F::urlLinkPayload('https://example.test/about', 'About us'), 'newWindow' => true,
                'ariaLabel' => 'Company history', 'linkTitle' => 'Founders story', 'classes' => 'btnprimary',
                'urlSuffix' => '#suffixanchor', 'customAttributes' => [['attribute' => 'data-track', 'value' => 'trackingtoken']],
                'fields' => [
                $caption->handle => 'Quarterly brochure',
                $note->handle => 'Internal reminder',
                $matrix->handle => ['entries' => ['new1' => ['type' => $blockType->handle, 'fields' => [
                    $inner->handle => [F::urlLinkPayload('https://example.test/nested', 'Nested destination')],
                ]]], 'sortOrder' => ['new1']],
            ]],
            ['type' => Email::class, 'handle' => 'email', 'linkValue' => 'hello@example.test', 'linkText' => 'Write'],
            ['type' => Phone::class, 'handle' => 'phone', 'linkValue' => '+15551234', 'linkText' => 'Ring'],
            F::entryLinkPayload($target, 'Read more'),
            ['type' => Embed::class, 'handle' => 'embed', 'linkText' => '', 'linkValue' => Embed::prepareEmbedData([
                'url' => 'https://video.example.test/watch?v=abc',
                'title' => 'Harbour timelapse',
                'description' => 'Providerblurb',
                'providerName' => 'Videohost',
                'code' => '<iframe src="https://video.example.test/embed/abc"></iframe>',
            ])],
        ],
    ]);

    $owner = Entry::find()->id($owner->id)->one();
    $value = $owner->getFieldValue($field->handle);
    $keywords = $field->getSearchKeywords($value, $owner);
    $tokens = hyperSearchTokens($keywords);

    expect($tokens)->toContain('about', 'us', 'write', 'ring', 'read', 'more')
        ->and($keywords)->toContain('https://example.test/about', 'hello@example.test', '+15551234')
        ->and($tokens)->toContain('quarterly', 'brochure', 'nested', 'destination');

    // Editor-written link attributes and the embed title editors see on the card stay searchable.
    expect($tokens)->toContain('company', 'history', 'founders', 'story', 'harbour', 'timelapse')
        ->and($keywords)->toContain('https://video.example.test/watch?v=abc');

    // Classes, URL suffixes, custom attributes and other embed data are markup, not content.
    expect($tokens)->not->toContain('btnprimary')
        ->and($tokens)->not->toContain('suffixanchor')
        ->and($tokens)->not->toContain('track')
        ->and($tokens)->not->toContain('trackingtoken')
        ->and($tokens)->not->toContain('providerblurb')
        ->and($tokens)->not->toContain('videohost')
        ->and($tokens)->not->toContain('iframe')
        ->and($tokens)->not->toContain('embed');

    // Link type handles, UIDs, the new-window flag and element IDs are internal.
    expect($tokens)->not->toContain('url')
        ->and($tokens)->not->toContain('email')
        ->and($tokens)->not->toContain('phone')
        ->and($tokens)->not->toContain('entry')
        ->and($tokens)->not->toContain('1')
        ->and($tokens)->not->toContain((string)$target->id);

    foreach ($value->getLinks() as $link) {
        expect($keywords)->not->toContain($link->uid);
    }

    // Craft only indexes nested fields that are marked searchable.
    expect($tokens)->not->toContain('internal')
        ->and($tokens)->not->toContain('reminder');

    // The same keywords reach Craft's search.
    Craft::$app->getSearch()->indexElementAttributes($owner, [$field->handle]);
    $search = fn(string $query) => Entry::find()->id($owner->id)->search($query)->ids();

    expect($search('brochure'))->toBe([$owner->id])
        ->and($search('ring'))->toBe([$owner->id])
        ->and($search('founders'))->toBe([$owner->id])
        ->and($search('timelapse'))->toBe([$owner->id])
        ->and($search('trackingtoken'))->toBe([])
        ->and($search('phone'))->toBe([])
        ->and($search($field->handle . ':url'))->toBe([]);
});

// Destinations aren't part of the keywords, so building them must not resolve linked elements.
it('builds keywords for element links without querying their destinations', function() {
    $targetSection = F::entrySection();
    $targets = array_map(fn() => F::plainEntry($targetSection), range(1, 10));
    $field = hyperSearchableField([F::linkTypeConfig(EntryLink::class)]);
    $owner = F::plainEntry(F::entrySection($field), 'Search owner', [
        $field->handle => array_map(fn($target, $i) => F::entryLinkPayload($target, 'Read chapter ' . $i), $targets, range(1, 10)),
    ]);

    // Search indexing runs in queue and console requests, where linked elements aren't primed.
    $owner = Entry::find()->id($owner->id)->one();
    Hyper::$plugin->getLinkRelations()->resetRequestState();
    $value = $field->normalizeValue($field->serializeValue($owner->getFieldValue($field->handle), $owner), $owner);
    $profile = QueryProfiler::profile(fn() => $field->getSearchKeywords($value, $owner));

    expect($profile['queries'])->toBe(0, json_encode($profile['topPatterns']));
    expect(hyperSearchTokens($field->getSearchKeywords($value, $owner)))->toContain('read', 'chapter', '10');
});
