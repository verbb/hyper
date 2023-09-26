<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;
use craft\helpers\StringHelper;

use presseddigital\linkit\fields\LinkitField;
use presseddigital\linkit\models\Asset;
use presseddigital\linkit\models\Category;
use presseddigital\linkit\models\Email;
use presseddigital\linkit\models\Entry;
use presseddigital\linkit\models\Facebook;
use presseddigital\linkit\models\Instagram;
use presseddigital\linkit\models\LinkedIn;
use presseddigital\linkit\models\Phone;
use presseddigital\linkit\models\Product;
use presseddigital\linkit\models\Twitter;
use presseddigital\linkit\models\Url;
use presseddigital\linkit\models\User;

class MigrateLinkitContent extends PluginContentMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        Asset::class => linkTypes\Asset::class,
        Category::class => linkTypes\Category::class,
        Email::class => linkTypes\Email::class,
        Entry::class => linkTypes\Entry::class,
        Phone::class => linkTypes\Phone::class,
        Product::class => linkTypes\Product::class,
        Url::class => linkTypes\Url::class,
        Twitter::class => linkTypes\Url::class,
        Facebook::class => linkTypes\Url::class,
        Instagram::class => linkTypes\Url::class,
        LinkedIn::class => linkTypes\Url::class,
        User::class => linkTypes\User::class,

        // Handle legacy types
        'fruitstudios\\linkit\\models\\Asset' => linkTypes\Asset::class,
        'fruitstudios\\linkit\\models\\Category' => linkTypes\Category::class,
        'fruitstudios\\linkit\\models\\Email' => linkTypes\Email::class,
        'fruitstudios\\linkit\\models\\Entry' => linkTypes\Entry::class,
        'fruitstudios\\linkit\\models\\Phone' => linkTypes\Phone::class,
        'fruitstudios\\linkit\\models\\Product' => linkTypes\Product::class,
        'fruitstudios\\linkit\\models\\Url' => linkTypes\Url::class,
        'fruitstudios\\linkit\\models\\Twitter' => linkTypes\Url::class,
        'fruitstudios\\linkit\\models\\Facebook' => linkTypes\Url::class,
        'fruitstudios\\linkit\\models\\Instagram' => linkTypes\Url::class,
        'fruitstudios\\linkit\\models\\LinkedIn' => linkTypes\Url::class,
        'fruitstudios\\linkit\\models\\User' => linkTypes\User::class,
    ];

    public string $oldFieldTypeClass = LinkitField::class;


    // Public Methods
    // =========================================================================

    public function convertModel(HyperField $field, array $oldSettings): bool|array|null
    {
        $oldType = $oldSettings['type'] ?? null;
        $hyperType = $oldSettings[0]['type'] ?? null;

        if (str_contains($hyperType, 'verbb\\hyper')) {
            $this->stdout('    > Content already migrated to Hyper content.', Console::FG_GREEN);

            return null;
        }

        // Return `null` for an empty field, or already migrated to Hyper.
        // `false` for when unable to find matching new type.
        if (!$oldType) {
            return null;
        }

        $linkTypeClass = $this->getLinkType($oldType);

        if (!$linkTypeClass) {
            $this->stdout("    > Unable to migrate “{$oldType}” class.", Console::FG_RED);

            return false;
        }

        $link = new $linkTypeClass();
        $link->handle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
        $link->linkValue = $oldSettings['value'] ?? null;
        $link->linkText = $oldSettings['customText'] ?? null;
        $link->newWindow = $oldSettings['target'] ?? false;

        return [$link->getSerializedValues()];
    }
}
