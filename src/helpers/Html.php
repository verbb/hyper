<?php
namespace verbb\hyper\helpers;

use craft\helpers\Html as CraftHtml;

class Html extends CraftHtml
{
    // Static Methods
    // =========================================================================

    public static function normalizeTagAttributes(array $attributes): array
    {
        $normalized = parent::normalizeTagAttributes($attributes);

        // `normalizeTagAttributes` won't handle `rel` attributes, which is valid to have merged
        foreach ($normalized as $name => $value) {
            if ($name === 'rel') {
                $normalized[$name] = static::explodeClass($value);
            }
        }

        return $normalized;
    }

}