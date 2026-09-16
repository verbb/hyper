<?php
namespace verbb\hyper\content;

/** Preserve untouched JSON objects, including empty and numerically keyed objects. */
final class RawJson
{
    // Static Methods
    // =========================================================================

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function preserve(string $source, array $changed): mixed
    {
        return self::_merge(json_decode($source, false, 512, JSON_THROW_ON_ERROR), json_decode($source, true, 512, JSON_THROW_ON_ERROR), $changed);
    }

    private static function _merge(mixed $original, mixed $before, mixed $after): mixed
    {
        if ($before === $after) {
            return $original;
        }
        if (!is_array($before) || !is_array($after) || array_is_list($before) !== array_is_list($after)) {
            return $after;
        }
        $result = $original instanceof \stdClass ? new \stdClass() : [];
        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before)) {
                $old = $original instanceof \stdClass ? $original->{(string)$key} : $original[$key];
                $value = self::_merge($old, $before[$key], $value);
            }
            if ($result instanceof \stdClass) {
                $result->{(string)$key} = $value;
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
