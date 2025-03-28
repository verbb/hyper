<?php
namespace verbb\hyper\gql\types\generators;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\fields\HyperField;
use verbb\hyper\gql\interfaces\LinkInterface;
use verbb\hyper\gql\types\LinkType;

use Craft;
use craft\errors\GqlException;
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

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof HyperField) {
                    foreach ($field->getLinkTypes() as $linkType) {
                        $linkType->field = $field;

                        $type = static::generateType($linkType);
                        $gqlTypes[$type->name] = $type;
                    }
                }
            }
        }

        return $gqlTypes;
    }

    public static function generateType(mixed $context): mixed
    {
        $typeName = Link::gqlTypeNameByContext($context);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new LinkType([
            'name' => $typeName,
            'fields' => function() use ($context, $typeName) {
                $contentFieldGqlTypes = self::getContentFields($context);
                $linkTypeFields = array_merge(LinkInterface::getFieldDefinitions(), $contentFieldGqlTypes);

                return Craft::$app->getGql()->prepareFieldDefinitions($linkTypeFields, $typeName);
            },
        ]));
    }


    // Protected Methods
    // =========================================================================

    protected static function getContentFields($context): array
    {
        try {
            $schema = Craft::$app->getGql()->getActiveSchema();
        } catch (GqlException $e) {
            Craft::warning("Could not get the active GraphQL schema: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
            return [];
        }

        $contentFieldGqlTypes = [];

        if ($fieldLayout = $context->getFieldLayout()) {
            foreach ($fieldLayout->getCustomFields() as $contentField) {
                if ($contentField->includeInGqlSchema($schema)) {
                    $contentFieldGqlTypes[$contentField->handle] = $contentField->getContentGqlType();
                }
            }
        }

        return $contentFieldGqlTypes;
    }
}
