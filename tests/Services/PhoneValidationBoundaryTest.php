<?php

use verbb\hyper\links\Phone;

it('accepts zero as a phone dial string without treating the validated value as false', function() {
    $link = new Phone(['linkValue' => '0']);

    expect($link->validate(['linkValue']))->toBeTrue();
    expect($link->getUrl())->toBe('tel:0');
});

it('still rejects invalid phone dial strings', function() {
    $link = new Phone(['linkValue' => 'call me']);

    expect($link->validate(['linkValue']))->toBeFalse();
});
