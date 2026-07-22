<?php
namespace verbb\hyper\content;

use verbb\hyper\content\locators\MatrixNestedFieldLocator;
use verbb\hyper\content\locators\NeoNestedFieldLocator;
use verbb\hyper\content\locators\SuperTableNestedFieldLocator;
use verbb\hyper\content\locators\VizyNestedFieldLocator;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\FieldInterface;

class NestedFieldLocatorRegistry
{
    // Properties
    // =========================================================================

    private static ?array $_locators = null;


    // Static Methods
    // =========================================================================

    public static function all(): array
    {
        if (self::$_locators !== null) {
            return array_values(self::$_locators);
        }

        self::$_locators = [];

        foreach ([
            MatrixNestedFieldLocator::class,
            SuperTableNestedFieldLocator::class,
            VizyNestedFieldLocator::class,
            NeoNestedFieldLocator::class,
        ] as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $locator = new $class();
            self::$_locators[$locator::hostFieldClass()] = $locator;
        }

        return array_values(self::$_locators);
    }

    public static function getFor(FieldInterface $hostField): ?NestedFieldLocator
    {
        self::all();

        return self::$_locators[$hostField::class] ?? null;
    }
}
