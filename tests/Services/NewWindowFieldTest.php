<?php

declare(strict_types=1);

use craft\fieldlayoutelements\BaseNativeField;
use verbb\hyper\fieldlayoutelements\NewWindowField;
use verbb\hyper\links\Url;

it('defines New Window as a BaseNativeField on the newWindow attribute', function() {
    $element = Craft::createObject(NewWindowField::class);

    expect($element)->toBeInstanceOf(BaseNativeField::class);
    expect($element->attribute)->toBe('newWindow');
    expect($element->defaultLabel())->toBe('New Window');
});

it('excludes New Window from the default link field layout like ARIA Label', function() {
    $layout = Url::getDefaultFieldLayout();

    expect($layout->isFieldIncluded('newWindow'))->toBeFalse();
    expect($layout->isFieldIncluded('ariaLabel'))->toBeFalse();
    expect($layout->isFieldIncluded('linkValue'))->toBeTrue();
});
