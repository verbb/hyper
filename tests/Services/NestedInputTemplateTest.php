<?php

use craft\fieldlayoutelements\CustomField;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('renders nested revision fields as static without leaking into editable renders', function() {
    $inner = F::hyperField(['multipleLinks' => true]);
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($inner)]);
    $url->setFieldLayout($layout);
    $outer = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($outer), 'Static nested owner', [
        $outer->handle => [[...F::urlLinkPayload('/parent'), 'fields' => [
            $inner->handle => [F::urlLinkPayload('/child')],
        ]]],
    ]);

    \Tests\Support\CpActionRequest::asUser(\craft\elements\User::find()->admin()->one(), function() use ($outer, $owner) {
        $view = Craft::$app->getView();
        $namespace = $view->getNamespace();
        $view->setNamespace('fields');
        try {
            foreach ([true, false] as $static) {
                $value = $owner->getFieldValue($outer->handle);
                $html = $static ? $outer->getStaticHtml($value, $owner) : $outer->getInputHtml($value, $owner);
                preg_match_all('/data-hyper-input-config="([^"]+)"/', $html, $matches);
                expect(count($matches[1]))->toBeGreaterThan(1);
                foreach ($matches[1] as $json) {
                    $config = json_decode(html_entity_decode($json, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
                    expect($config['settings']['isStatic'])->toBe($static);
                }
                expect($html)->not->toContain('Link fields unavailable');
            }
        } finally {
            $view->setNamespace($namespace);
        }
    });
});

it('keeps nested Hyper template placeholders independent when hydrating new links', function() {
    $inner = F::hyperField(['multipleLinks' => true]);
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($inner)]);
    $url->setFieldLayout($layout);
    $outer = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($outer), 'Nested template owner');
    $blocks = \Tests\Support\CpActionRequest::asUser(\craft\elements\User::find()->admin()->one(),
        fn() => $outer->getHydratedLinkBlocks('url', [F::urlLinkPayload('/parent')], $owner, false),
    );
    expect($blocks)->toHaveCount(1);
    $block = $blocks[0];
    expect($block)->toHaveKey('placeholder');
    preg_match_all('/data-link-placeholder="([^"]+)"/', $block['html'], $matches);
    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $placeholder) {
        expect($placeholder)->not->toBe($block['placeholder']);
        // Adding/pasting the parent must not consume its children's future row IDs.
        expect(str_replace($block['placeholder'], 'parent-row', $block['html']))->toContain($placeholder);
    }
    expect($block['html'])->toContain($block['placeholder']);
});
