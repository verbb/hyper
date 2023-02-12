<?php
namespace verbb\hyper\gql\types\generators;

use verbb\hyper\fields\HyperField;
use verbb\hyper\gql\types\LinkType;

use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class LinkTypeGenerator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        return [static::generateType($context)];
    }

    public static function getName($context = null): string
    {
        /** @var HyperField $context */
        return $context->handle . '_Link';
    }

    public static function generateType(mixed $context): ObjectType
    {
        /** @var HyperField $context */
        $typeName = self::getName($context);
        $contentFields = LinkType::prepareRowFieldDefinition();

        return GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new LinkType([
            'name' => $typeName,
            'fields' => function() use ($contentFields, $typeName) {
                return Craft::$app->getGql()->prepareFieldDefinitions($contentFields, $typeName);
            },
        ]));
    }
}
