<?php

use craft\elements\User;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('renders native link text defaults without losing authored text', function(?string $text, string $expected) {
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $layout->getField('linkText')->defaultValue = 'Shared fallback';
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($field), 'Link text default owner');
    $payload = F::urlLinkPayload('/campaign');
    $payload['linkText'] = $text;
    $blocks = CpActionRequest::asUser(User::find()->admin()->one(),
        fn() => $field->getHydratedLinkBlocks('url', [$payload], $owner, false),
    );

    expect($blocks)->toHaveCount(1);
    expect($blocks[0]['html'])->not->toContain('Link fields unavailable');
    expect($blocks[0]['html'])->toContain('value="' . $expected . '"');
})->with([
    'new link' => [null, 'Shared fallback'],
    'blank text' => ['', 'Shared fallback'],
    'authored text' => ['Campaign CTA', 'Campaign CTA'],
]);
