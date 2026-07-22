<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\models\LinkInstance;

use Craft;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

class Passive extends Link
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Passive');
    }

    public static function isInstanceEmpty(LinkInstance $instance): bool
    {
        return !$instance->hasMeaningfulAttributes();
    }

    public static function getDefaultFieldLayout(): FieldLayout
    {
        $fieldLayout = new FieldLayout([
            'type' => static::class,
        ]);

        $tab1 = new FieldLayoutTab(['name' => 'Content']);
        $tab1->setLayout($fieldLayout);
        $tab1->setElements([
            Craft::createObject([
                'class' => LinkTextField::class,
                'placeholder' => Craft::t('hyper', 'e.g. Read more'),
            ]),
        ]);

        $tab2 = new FieldLayoutTab(['name' => 'Advanced']);
        $tab2->setLayout($fieldLayout);
        $tab2->setElements([
            Craft::createObject([
                'class' => LinkTitleField::class,
            ]),
            Craft::createObject([
                'class' => ClassesField::class,
            ]),
            Craft::createObject([
                'class' => CustomAttributesField::class,
            ]),
        ]);

        $fieldLayout->setTabs([$tab1, $tab2]);

        return $fieldLayout;
    }


    // Public Methods
    // =========================================================================

    public function getText(?string $defaultText = null): ?string
    {
        return $this->getLinkText() ?: $defaultText ?: null;
    }

    public function getUrl(): ?string
    {
        return null;
    }
}
