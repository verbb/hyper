<?php
namespace verbb\hyper\helpers;

use verbb\hyper\base\LinkInterface;

use craft\elements\db\ElementQueryInterface;

use yii\base\UnknownPropertyException;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;
use Traversable;

/**
 * Compiles in-memory link conditions without assigning database identities to links.
 */
class LinkCollectionFilter
{
    // Static Methods
    // =========================================================================

    public static function predicate(array $condition): Closure
    {
        if (!array_is_list($condition) || $condition === []) {
            $predicates = [];

            foreach ($condition as $attribute => $expected) {
                $predicates[] = self::predicate([is_array($expected) ? 'in' : '=', $attribute, $expected]);
            }

            return self::_combine('and', $predicates);
        }

        $operator = strtolower((string)array_shift($condition));

        if (in_array($operator, ['and', 'or', 'not'], true)) {
            if (!$condition || ($operator === 'not' && count($condition) !== 1)) {
                throw new InvalidArgumentException("Invalid operands for '$operator'.");
            }

            $predicates = [];

            foreach ($condition as $operand) {
                if (!is_array($operand)) {
                    throw new InvalidArgumentException('Each Boolean operand must be a condition array.');
                }

                $predicates[] = self::predicate($operand);
            }

            return self::_combine($operator, $predicates);
        }

        $operators = ['=', '==', '===', '!=', '<>', '!==', '>', '>=', '<', '<=', 'in', 'not in', 'between', 'not between', 'like', 'not like'];
        $between = in_array($operator, ['between', 'not between'], true);

        if (!in_array($operator, $operators, true) || count($condition) !== ($between ? 3 : 2) || !is_string($condition[0])) {
            throw new InvalidArgumentException("Invalid link condition for '$operator'.");
        }

        [$attribute, $expected] = $condition;

        if (in_array($operator, ['in', 'not in'], true)) {
            if (!is_array($expected)) {
                throw new InvalidArgumentException('An IN condition requires an array of values.');
            }

            $expected = array_map(self::_comparable(...), $expected);
        } else {
            $expected = self::_comparable($expected);
        }

        $upper = $between ? self::_comparable($condition[2]) : null;

        return static function(LinkInterface $link) use ($attribute, $operator, $expected, $upper): bool {
            $actual = self::value($link, $attribute);

            return match ($operator) {
                '=', '==' => $actual == $expected,
                '===' => $actual === $expected,
                '!=', '<>' => $actual != $expected,
                '!==' => $actual !== $expected,
                '>' => $actual !== null && $actual > $expected,
                '>=' => $actual !== null && $actual >= $expected,
                '<' => $actual !== null && $actual < $expected,
                '<=' => $actual !== null && $actual <= $expected,
                'in' => in_array($actual, $expected),
                'not in' => !in_array($actual, $expected),
                'between' => $actual !== null && $actual >= $expected && $actual <= $upper,
                'not between' => $actual !== null && ($actual < $expected || $actual > $upper),
                'like' => $actual !== null && stripos((string)$actual, (string)$expected) !== false,
                'not like' => $actual !== null && stripos((string)$actual, (string)$expected) === false,
            };
        };
    }

    public static function value(LinkInterface $link, string $attribute): mixed
    {
        // Resolve custom fields through Craft, rather than comparing stored JSON.
        // The fields. prefix disambiguates handles that collide with native properties.
        if (str_starts_with($attribute, 'fields.')) {
            $handle = substr($attribute, 7);
            $value = $link->getFieldLayout()?->getFieldByHandle($handle) ? $link->getFieldValue($handle) : null;
        } else {
            try {
                // Native getters win over identically named custom fields.
                $getter = 'get' . ucfirst($attribute);
                $value = method_exists($link, $getter) ? $link->$getter() : $link->$attribute;
            } catch (UnknownPropertyException) {
                // Different link layouts need not contain the same custom fields.
                $value = null;
            }
        }

        return self::_comparable($value);
    }

    public static function orderBy(array|string $columns): array
    {
        if (is_string($columns)) {
            $order = [];

            foreach (preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY) as $column) {
                if (!preg_match('/^([\w.]+)(?:\s+(asc|desc))?$/i', $column, $matches)) {
                    throw new InvalidArgumentException('Invalid link ordering expression.');
                }

                $order[$matches[1]] = strtolower($matches[2] ?? 'asc') === 'desc' ? SORT_DESC : SORT_ASC;
            }

            return $order;
        }

        foreach ($columns as $attribute => $direction) {
            if (!is_string($attribute) || !in_array($direction, [SORT_ASC, SORT_DESC], true)) {
                throw new InvalidArgumentException('Link ordering maps attribute names to SORT_ASC or SORT_DESC.');
            }
        }

        return $columns;
    }

    private static function _combine(string $operator, array $predicates): Closure
    {
        return static function(LinkInterface $link) use ($operator, $predicates): bool {
            if ($operator === 'not') {
                return !$predicates[0]($link);
            }

            foreach ($predicates as $predicate) {
                $matches = $predicate($link);

                if ($operator === 'and' && !$matches) {
                    return false;
                }

                if ($operator === 'or' && $matches) {
                    return true;
                }
            }

            return $operator === 'and';
        };
    }

    private static function _comparable(mixed $value): mixed
    {
        if ($value === null || is_scalar($value) || $value instanceof DateTimeInterface) {
            return $value;
        }

        if ($value instanceof Stringable && !$value instanceof ElementQueryInterface && !$value instanceof Traversable) {
            return (string)$value;
        }

        throw new InvalidArgumentException('Link conditions and ordering support scalar values and dates, not relation queries or complex field values.');
    }
}
