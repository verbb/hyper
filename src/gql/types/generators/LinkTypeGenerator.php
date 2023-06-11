<?php
namespace verbb\hyper\gql\types\generators;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\fields\HyperField;
use verbb\hyper\gql\interfaces\LinkInterface;
use verbb\hyper\gql\types\LinkType;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class LinkTypeGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    // Static Methods
    // =========================================================================

    public static function generateTypes(mixed $context = null): array
    {
        $gqlTypes = [];

        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if ($field instanceof HyperField) {
                foreach ($field->getLinkTypes() as $linkType) {
                    $linkType->field = $field;

                    $type = static::generateType($linkType);
                    $gqlTypes[$type->name] = $type;
                }
            }
        }

        return $gqlTypes;
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Link::gqlTypeNameByContext($context);

        if ($createdType = GqlEntityRegistry::getEntity($typeName)) {
            return $createdType;
        }

        $contentFieldGqlTypes = self::getContentFields($context);
        $linkTypeFields = array_merge(LinkInterface::getFieldDefinitions(), $contentFieldGqlTypes);

        return GqlEntityRegistry::createEntity($typeName, new LinkType([
            'name' => $typeName,
            'fields' => function() use ($linkTypeFields, $typeName) {
                return Craft::$app->getGql()->prepareFieldDefinitions($linkTypeFields, $typeName);
            },
        ]));
    }
}
