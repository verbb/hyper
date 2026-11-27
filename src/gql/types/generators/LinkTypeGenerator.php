<?php
namespace verbb\hyper\gql\types\generators;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\fields\HyperField;
use verbb\hyper\gql\interfaces\LinkInterface;
use verbb\hyper\gql\types\LinkType;
use verbb\hyper\links\MissingLink;

use Craft;
use craft\base\EagerLoadingFieldInterface;
use craft\base\FieldInterface;
use craft\errors\GqlException;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\services\Gql as GqlService;

use GraphQL\Type\Definition\Type;

class LinkTypeGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    // Static Methods
    // =========================================================================

    public static function generateTypes(mixed $context = null): array
    {
        // Opaque content may outlive its configured type. Register one schema-safe
        // fallback even when no field currently contains an unavailable prototype.
        $missingType = static::generateType(new MissingLink());
        $gqlTypes = [$missingType->name => $missingType];

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
        $typeName = $context instanceof MissingLink
            ? MissingLink::gqlTypeNameByContext($context)
            : Link::gqlTypeNameByContext($context);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new LinkType([
            'name' => $typeName,
            'fields' => function() use ($context, $typeName) {
                $contentFieldGqlTypes = self::getContentFields($context);
                // Colliding custom values remain available through the fields JSON bag.
                $linkTypeFields = LinkInterface::getFieldDefinitions() + $contentFieldGqlTypes;

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
                    $contentFieldGqlTypes[$contentField->handle] = self::_getContentFieldDefinition($contentField);
                }
            }
        }

        return $contentFieldGqlTypes;
    }

    private static function _getContentFieldDefinition(FieldInterface $field): mixed
    {
        $definition = $field->getContentGqlType();

        // Link layouts aren't eager-loaded, so a field Craft would eager-load runs its queries once per link.
        if (!$field instanceof EagerLoadingFieldInterface) {
            return $definition;
        }

        if ($definition instanceof Type) {
            $definition = ['name' => $field->handle, 'type' => $definition];
        }

        if (is_array($definition)) {
            // Add to the field's own score so its argument costs, such as `relatedTo`, still count.
            $fieldComplexity = $definition['complexity'] ?? null;
            $definition['complexity'] = static fn($childComplexity, $args = []) => ($fieldComplexity ? $fieldComplexity($childComplexity, $args) : $childComplexity) + GqlService::GRAPHQL_COMPLEXITY_NPLUS1;
        }

        return $definition;
    }
}
