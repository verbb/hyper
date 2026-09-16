<?php
namespace verbb\hyper\helpers;

use craft\helpers\Html as CraftHtml;

class Html extends CraftHtml
{
    // Static Methods
    // =========================================================================

    public static function renderTagAttributes($attributes): string
    {
        if (isset($attributes['class']) && is_array($attributes['class'])) {
            $classes = $attributes['class'];

            if (static::$normalizeClassAttribute && count($classes) > 1) {
                $classes = array_unique(explode(' ', implode(' ', $classes)));
            }

            // Yii's class-array filter drops the valid token "0". Preserve it
            // without changing class normalization or the public attribute array.
            $attributes['class'] = implode(' ', array_filter($classes, static fn(mixed $value): bool => (bool)$value || $value === '0' || $value === 0));
        }

        return parent::renderTagAttributes($attributes);
    }
}
