<?php

use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\links\Embed;

it('prefers an authored Embed title over provider metadata', function(string $title) {
    $link = new Embed([
        'linkValue' => ['url' => 'https://example.test/video', 'description' => 'Provider description'],
        'linkTitle' => $title,
    ]);
    $link->setFieldLayout(Embed::getDefaultFieldLayout());

    expect($link->getTitle())->toBe($title);
    expect($link->getLinkAttributes()['title'] ?? null)->toBe($title);
    expect((string)$link->getLink())->toContain('title="' . $title . '"');
})->with(['Authored title', '0']);

it('uses provider description when the Embed title is empty', function(?string $title) {
    $link = new Embed([
        'linkValue' => ['url' => 'https://example.test/video', 'description' => 'Provider description'],
        'linkTitle' => $title,
    ]);
    $link->setFieldLayout(Embed::getDefaultFieldLayout());

    expect($link->getTitle())->toBe('Provider description');
})->with([null, '']);

it('omits the Embed title when its layout field is removed', function() {
    $link = new Embed([
        'linkValue' => ['url' => 'https://example.test/video', 'description' => 'Provider description'],
        'linkTitle' => 'Authored title',
    ]);
    $layout = Embed::getDefaultFieldLayout();
    foreach ($layout->getTabs() as $tab) {
        $tab->setElements(array_filter($tab->getElements(), fn($element) => !$element instanceof LinkTitleField));
    }
    $link->setFieldLayout($layout);

    expect($link->getLinkAttributes())->not->toHaveKey('title');
});
