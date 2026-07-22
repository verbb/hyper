<?php

declare(strict_types=1);

use Tests\Support\Fixtures\HyperFixtureFactory;
use verbb\hyper\base\Link;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;

it('builds graphql type names from stable link type handles', function() {
    $field = HyperFixtureFactory::hyperField();
    $linkType = Hyper::$plugin->getLinks()->createLink(Url::class);
    $linkType->field = $field;
    $linkType->label = 'Lien URL accentué';
    $linkType->handle = 'accented-url';

    $typeName = Link::gqlTypeNameByContext($linkType);

    expect($typeName)->toBe($field->handle . '_AccentedUrl_LinkType');
    expect($typeName)->not->toContain('accentu');
    expect($typeName)->not->toContain('Lien');
});

it('does not change graphql type names when the cp label changes', function() {
    $field = HyperFixtureFactory::hyperField();
    $linkType = Hyper::$plugin->getLinks()->createLink(Url::class);
    $linkType->field = $field;
    $linkType->handle = 'stable-handle';
    $linkType->label = 'Original label';

    $original = Link::gqlTypeNameByContext($linkType);

    $linkType->label = 'Etiquette modifiée avec des accents';

    expect(Link::gqlTypeNameByContext($linkType))->toBe($original);
});
