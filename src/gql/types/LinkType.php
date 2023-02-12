<?php
namespace verbb\hyper\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\interfaces\Element;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

class LinkType extends ObjectType
{
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $fieldName = $resolveInfo->fieldName;

        return $source[$fieldName];
    }

    public static function prepareRowFieldDefinition(): array
    {
        return [
            'ariaLabel' => [
                'name' => 'ariaLabel',
                'description' => 'The `aria-label` attribute for the link.',
                'type' => Type::string(),
            ],
            'classes' => [
                'name' => 'classes',
                'description' => 'The `class` attribute for the link.',
                'type' => Type::string(),
            ],
            'element' => [
                'name' => 'element',
                'description' => 'The element (if provided) for the link.',
                'type' => Element::getType(),
            ],
            'isElement' => [
                'name' => 'isElement',
                'description' => 'Whether the chosen link value is an element.',
                'type' => Type::boolean(),
            ],
            'isEmpty' => [
                'name' => 'isEmpty',
                'description' => 'Whether a link has been set for the field.',
                'type' => Type::boolean(),
            ],
            'link' => [
                'name' => 'link',
                'description' => 'The HTML output for a `<a>` element.',
                'type' => Type::string(),
            ],
            'linkText' => [
                'name' => 'linkText',
                'description' => 'The text for the link.',
                'type' => Type::string(),
            ],
            'linkUrl' => [
                'name' => 'linkUrl',
                'description' => 'The url for the link.',
                'type' => Type::string(),
            ],
            'newWindow' => [
                'name' => 'newWindow',
                'description' => 'Whether the link should open in a new window.',
                'type' => Type::boolean(),
            ],
            'target' => [
                'name' => 'target',
                'description' => 'The `target` attribute for the link.',
                'type' => Type::string(),
            ],
            'text' => [
                'name' => 'text',
                'description' => 'The text for the link.',
                'type' => Type::string(),
            ],
            'title' => [
                'name' => 'title',
                'description' => 'The `title` attribute for the link.',
                'type' => Type::string(),
            ],
            'type' => [
                'name' => 'type',
                'description' => 'The link type.',
                'type' => Type::string(),
            ],
            'url' => [
                'name' => 'url',
                'description' => 'The url for the link.',
                'type' => Type::string(),
            ],
            'urlPrefix' => [
                'name' => 'urlPrefix',
                'description' => 'The url prefix for the link.',
                'type' => Type::string(),
            ],
            'urlSuffix' => [
                'name' => 'urlSuffix',
                'description' => 'The url suffix for the link.',
                'type' => Type::string(),
            ],
        ];
    }
}
