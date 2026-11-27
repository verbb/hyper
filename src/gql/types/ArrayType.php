<?php
namespace verbb\hyper\gql\types;

use craft\gql\GqlEntityRegistry;

use GraphQL\Type\Definition\ScalarType;

/**
 * Returns link custom attributes as structured data.
 * It's named `HyperArrayType` in the schema, so it can't clash with another plugin's `ArrayType` scalar.
 */
class ArrayType extends ScalarType
{
    // Static Methods
    // =========================================================================

    public static function getType()
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self(['name' => self::getName()]));
    }

    public static function getName(): string
    {
        return 'HyperArrayType';
    }


    // Public Methods
    // =========================================================================

    public function serialize($value)
    {
        if (!is_array($value)) {
            $value->toArray();
        }

        return $value;
    }

    public function parseValue($value)
    {
        return $value;
    }

    public function parseLiteral($valueNode, array $variables = null)
    {
        return $valueNode;
    }
}
