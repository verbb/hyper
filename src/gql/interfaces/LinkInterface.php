<?php
namespace verbb\hyper\gql\interfaces;

use verbb\hyper\base\Link;
use verbb\hyper\gql\types\generators\LinkTypeGenerator;
use verbb\hyper\gql\types\ArrayType;

use Craft;
use craft\gql\base\InterfaceType as BaseInterfaceType;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use craft\helpers\Json;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class LinkInterface extends BaseInterfaceType
{
    // Static Methods
    // =========================================================================

    public static function getTypeGenerator(): string
    {
        return LinkTypeGenerator::class;
    }

    public static function getType($context = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all link fields.',
            'resolveType' => function($value) {
                return $value->getGqlTypeName();
            },
        ]));

        LinkTypeGenerator::generateTypes($context);

        return $type;
    }

    public static function getName(): string
    {
        return 'HyperLinkInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions([
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
                'resolve' => function($link) {
                    return $link->isElement();
                },
            ],
            'isEmpty' => [
                'name' => 'isEmpty',
                'description' => 'Whether a link has been set for the field.',
                'type' => Type::boolean(),
                'resolve' => function($link) {
                    return $link->isEmpty();
                },
            ],
            'link' => [
                'name' => 'link',
                'description' => 'The HTML output for a `<a>` element.',
                'type' => Type::string(),
            ],
            'linkText' => [
                'name' => 'linkText',
                'description' => 'The text for the link (includes type-specific fallbacks such as element titles when the Link Text field is empty).',
                'type' => Type::string(),
            ],
            'customLinkText' => [
                'name' => 'customLinkText',
                'description' => 'Only the text entered in the Link Text field, with no fallbacks. Null when blank.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return $link->getCustomLinkText();
                },
            ],
            'linkUrl' => [
                'name' => 'linkUrl',
                'description' => 'The url for the link.',
                'type' => Type::string(),
            ],
            'linkValue' => [
                'name' => 'linkValue',
                'description' => 'The raw link data as a JSON string (full embed metadata for Embed links).',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return Json::encode($link->linkValue);
                },
            ],
            'html' => [
                'name' => 'html',
                'description' => 'Embed HTML (`code`) when this is an Embed link; otherwise null.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    $html = $link->getHtml();

                    return $html !== null ? (string)$html : null;
                },
            ],
            'iframeSrc' => [
                'name' => 'iframeSrc',
                'description' => 'The `src` attribute from the first iframe in embed HTML, when present.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return $link->getIframeSrc();
                },
            ],
            'embedImage' => [
                'name' => 'embedImage',
                'description' => 'Thumbnail/image URL from embed metadata, when present.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return $link->getEmbedImage();
                },
            ],
            'providerName' => [
                'name' => 'providerName',
                'description' => 'oEmbed provider name for Embed links (e.g. YouTube), when present.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return $link->getEmbedProviderName();
                },
            ],
            'fields' => [
                'name' => 'fields',
                'description' => 'Custom layout field values keyed by handle (JSON). Use when you need layout fields without casting to a concrete link type.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return Json::encode($link->getSerializedLayoutFields());
                },
            ],
            'newWindow' => [
                'name' => 'newWindow',
                'description' => 'Whether the link should open in a new window.',
                'type' => Type::boolean(),
                'resolve' => function($link) {
                    return $link->getNewWindow();
                },
            ],
            'target' => [
                'name' => 'target',
                'description' => 'The `target` attribute for the link.',
                'type' => Type::string(),
                'resolve' => function($link) {
                    return $link->getTarget();
                },
            ],
            'text' => [
                'name' => 'text',
                'description' => 'The fully derived link label (custom text, type fallbacks, then field placeholder or plugin default).',
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
            'linkUri' => [
                'name' => 'linkUri',
                'description' => 'The uri for the link (if an element-based link).',
                'type' => Type::string(),
            ],
            'customAttributes' => [
                'name' => 'customAttributes',
                'type' => ArrayType::getType(),
                'description' => 'The custom attributes for the link.',
                'resolve' => function($link) {
                    return $link->getCustomAttributes();
                },
            ],
        ], self::getName());
    }
}
