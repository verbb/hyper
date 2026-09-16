<?php

use craft\base\Field;
use craft\elements\Entry;
use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps translated draft structure edits separate from published owners', function() {
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true, 'translationMethod' => Field::TRANSLATION_METHOD_SITE]);
    $owner = F::plainEntry(F::translatableEntrySection($field, 2), 'Published owner', [
        $field->handle => [F::urlLinkPayload('https://example.test/published')],
    ], $primary);
    $draft = Craft::$app->drafts->createDraft($owner, User::find()->admin()->one()->id, 'Draft links');
    $draft->setFieldValue($field->handle, [F::urlLinkPayload('https://example.test/draft'), F::urlLinkPayload('https://example.test/new')]);
    expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
    foreach ([$primary, $secondary] as $site) {
        $published = Entry::find()->id($owner->id)->siteId($site->id)->one();
        expect(array_map(fn($link) => $link->getUrl(), $published->getFieldValue($field->handle)->getLinks()))->toBe(['https://example.test/published']);
    }
    $savedDraft = Entry::find()->id($draft->id)->drafts(true)->siteId($primary->id)->status(null)->one();
    expect(array_map(fn($link) => $link->getUrl(), $savedDraft->getFieldValue($field->handle)->getLinks()))->toBe(['https://example.test/draft', 'https://example.test/new']);
});
