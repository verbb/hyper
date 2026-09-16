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
        // Element property access prefers custom fields; native GraphQL values must retain their getters.
        return match ($resolveInfo->fieldName) {
            'link' => $source->getLink(),
            'linkText' => $source->getLinkText(),
            'linkUrl' => $source->getLinkUrl(),
            'text' => $source->getText(),
            'title' => $source->getTitle(),
            'type' => $source->getType(),
            'url' => $source->getUrl(),
            'urlPrefix' => $source->getUrlPrefix(),
            'linkUri' => $source->getLinkUri(),
            default => $source[$resolveInfo->fieldName],
        };
    }
}
