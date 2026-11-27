<?php

declare(strict_types=1);

use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\PlainText;
use craft\migrations\BaseFieldMergeMigration;
use Tests\Support\Fixtures\HyperFixtureFactory as F;

function fieldMergeCompatibilityPlainText(): PlainText
{
    $handle = F::handle('hyperMergeText');
    $field = new PlainText(['name' => $handle, 'handle' => $handle]);

    if (!Craft::$app->getFields()->saveField($field)) {
        throw new RuntimeException('Failed creating Plain Text field fixture.');
    }

    return $field;
}

it('refuses to merge Hyper fields with textual field types', function(string $class) {
    $hyper = F::hyperField();
    $other = new $class(['name' => 'Other', 'handle' => F::handle('hyperMergeOther')]);

    $reason = null;
    expect($hyper->canMergeInto($other, $reason))->toBeFalse()
        ->and($reason)->toBe('Hyper fields can only be merged into other Hyper fields.');

    $reason = null;
    expect($hyper->canMergeFrom($other, $reason))->toBeFalse()
        ->and($reason)->toBe('Only Hyper fields can be merged into Hyper fields.');
})->with([
    'Plain Text' => [PlainText::class],
    'Email' => [Email::class],
    'Dropdown' => [Dropdown::class],
]);

it('still merges Hyper fields with other Hyper fields', function() {
    $persisting = F::hyperField();
    $outgoing = F::hyperField();

    $reason = null;
    expect($outgoing->canMergeInto($persisting, $reason))->toBeTrue()
        ->and($persisting->canMergeFrom($outgoing, $reason))->toBeTrue()
        ->and($reason)->toBeNull();
});

it('stops the field merge migration in either direction between Hyper and Plain Text', function(bool $hyperPersists) {
    $hyper = F::hyperField();
    $text = fieldMergeCompatibilityPlainText();
    [$persisting, $outgoing] = $hyperPersists ? [$hyper, $text] : [$text, $hyper];

    $migration = new BaseFieldMergeMigration();
    $migration->persistingFieldUid = $persisting->uid;
    $migration->outgoingFieldUid = $outgoing->uid;

    ob_start();

    try {
        $result = $migration->safeUp();
    } finally {
        $output = (string)ob_get_clean();
        Craft::$app->getFields()->deleteField($text);
    }

    expect($result)->toBeFalse()
        ->and($output)->toContain($hyperPersists ? 'Only Hyper fields can be merged into Hyper fields.' : 'Hyper fields can only be merged into other Hyper fields.');
})->with([
    'Plain Text into Hyper' => [true],
    'Hyper into Plain Text' => [false],
]);
