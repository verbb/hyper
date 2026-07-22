<?php
namespace verbb\hyper\content;

use craft\base\FieldInterface;

interface JsonFieldAdapter
{
    // Public Methods
    // =========================================================================

    public static function fieldClass(): string;
    public function getField(): FieldInterface;
    public function decode(mixed $stored, ContentRef $ref): mixed;
    public function encode(mixed $value, ContentRef $ref): mixed;
    public function isEmpty(mixed $value): bool;
}
