<?php
namespace verbb\hyper\content\adapters;

use verbb\hyper\content\ContentRef;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\JsonFieldAdapter;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollection;

use craft\base\FieldInterface;

class HyperFieldAdapter implements JsonFieldAdapter
{
    // Properties
    // =========================================================================

    private HyperField $_field;


    // Public Methods
    // =========================================================================

    public function __construct(HyperField $field)
    {
        $this->_field = $field;
    }

    public static function fieldClass(): string
    {
        return HyperField::class;
    }

    public function getField(): FieldInterface
    {
        return $this->_field;
    }

    public function decode(mixed $stored, ContentRef $ref): LinkCollection
    {
        $stored = ElementContentStore::decodeStored($stored);

        if (!is_array($stored)) {
            $stored = [];
        }

        return new LinkCollection($this->_field, $stored);
    }

    public function encode(mixed $value, ContentRef $ref): mixed
    {
        if (!$value instanceof LinkCollection) {
            return [];
        }

        return $value->serializeValues();
    }

    public function isEmpty(mixed $value): bool
    {
        if (!$value instanceof LinkCollection) {
            return true;
        }

        return $value->isEmpty();
    }
}
