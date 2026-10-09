<?php

use craft\fieldlayoutelements\CustomField;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('keeps nested Hyper template placeholders independent when hydrating new links', function() {
    $inner = F::hyperField(['multipleLinks' => true]);
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($inner)]);
    $url->setFieldLayout($layout);
    $outer = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($outer), 'Nested template owner');
    $blocks = $outer->getHydratedLinkBlocks('url', [F::urlLinkPayload('/parent')], $owner, false);
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
