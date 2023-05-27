<?php
namespace verbb\hyper\integrations\feedme\fields;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\StringHelper;

use craft\feedme\base\Field;
use craft\feedme\base\FieldInterface;
use craft\feedme\helpers\DataHelper;

use Cake\Utility\Hash;

class Hyper extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    public static $name = 'HyperField';
    public static $class = HyperField::class;


    // Templates
    // =========================================================================

    public function getMappingTemplate(): string
    {
        return 'hyper/_integrations/feed-me/field';
    }


    // Public Methods
    // =========================================================================

    public function parseField(): ?array
    {
        $preppedData = [];

        $fields = Hash::get($this->fieldInfo, 'fields');

        if (!$fields) {
            return null;
        }

        foreach ($fields as $subFieldHandle => $subFieldInfo) {
            $preppedData[$subFieldHandle] = DataHelper::fetchValue($this->feedData, $subFieldInfo, $this->feed);
        }

        // Convert the link type to handle
        $typeMap = [
            'asset' => linkTypes\Asset::class,
            'category' => linkTypes\Category::class,
            'custom' => linkTypes\Custom::class,
            'email' => linkTypes\Email::class,
            'entry' => linkTypes\Entry::class,
            'site' => linkTypes\Site::class,
            'tel' => linkTypes\Phone::class,
            'url' => linkTypes\Url::class,
            'user' => linkTypes\User::class,
        ];

        $type = $preppedData['type'] ?? null;
        $linkTypeClass = $typeMap[$type] ?? null;

        if ($linkTypeClass) {
            $handle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
        } else {
            $handle = $type;
        }

        $preppedData['handle'] = $handle;
        unset($preppedData['type']);

        // Protect against sending an empty array
        if (!$preppedData) {
            return null;
        }

        return [$preppedData];
    }
}
