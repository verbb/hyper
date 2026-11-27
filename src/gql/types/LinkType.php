<?php
namespace verbb\hyper\gql\types;

use verbb\hyper\gql\interfaces\LinkInterface;

use craft\gql\base\ObjectType;

use GraphQL\Type\Definition\ResolveInfo;

class LinkType extends ObjectType
{
    // Public Methods
    // =========================================================================

    public function __construct(array $config)
    {
        $config['interfaces'] = [
            LinkInterface::getType(),
        ];

        parent::__construct($config);
    }


    // Protected Methods
    // =========================================================================

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        // Native GraphQL values use their getters. Link property access prefers Hyper's getters over
        // custom fields, so a content field outside the interface is read through `getFieldValue()`.
        $fieldName = $resolveInfo->fieldName;

        return match ($fieldName) {
            'link' => $source->getLink(),
            'linkText' => $source->getLinkText(),
            'linkUrl' => $source->getLinkUrl(),
            'text' => $source->getText(),
            'title' => $source->getTitle(),
            'type' => $source->getType(),
            'url' => $source->getUrl(),
            'urlPrefix' => $source->getUrlPrefix(),
            'linkUri' => $source->getLinkUri(),
            default => !LinkInterface::getType()->hasField($fieldName) && $source->getFieldLayout()?->getFieldByHandle($fieldName)
                ? $source->getFieldValue($fieldName)
                : $source[$fieldName],
        };
    }
}
