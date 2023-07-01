<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fieldlayoutelements\AriaLabelField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use Exception;

class PluginFieldMigration extends PluginMigration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        App::maxPowerCaptain();

        $this->fields = (new Query())
            ->from('{{%fields}}')
            ->where(['type' => $this->oldFieldTypeClass])
            ->all();

        $fieldService = Craft::$app->getFields();

        // Update the field settings
        $this->processFieldSettings();

        // Refresh the internal fields cache
        $fieldService->refreshFields();
        
        // Resave all fields to ensure they're properly saved in project config
        if ($this->resaveFields) {
            foreach ($this->fields as $fieldData) {
                $this->stdout("Re-saving field “{$fieldData['handle']}”.");

                $field = $fieldService->getFieldById($fieldData['id']);

                if (!$field) {
                    continue;
                }

                if (!$fieldService->saveField($field)) {
                    throw new Exception(Json::encode($field->getErrors()));
                }

                $this->stdout("    > Field “{$fieldData['handle']}” migration finalised." . PHP_EOL, Console::FG_GREEN);
            }
        }

        $this->stdout('Finished Migration' . PHP_EOL, Console::FG_GREEN);

        return true;
    }

    public static function getDefaultFieldLayout(bool $includeText = true, bool $enableTitle = true, bool $enableAriaLabel = false): FieldLayout
    {
        $fieldLayout = new FieldLayout([
            'type' => static::class,
        ]);

        // Populate the field layout
        $tab1 = new FieldLayoutTab(['name' => 'Content']);
        $tab1->setLayout($fieldLayout);

        $linkField = Craft::createObject([
            'class' => LinkField::class,
            'width' => 50,
        ]);

        $linkTextField = $includeText ? Craft::createObject([
            'class' => LinkTextField::class,
            'width' => 50,
        ]) : null;

        $tab1->setElements(array_filter([$linkField, $linkTextField]));

        $tab2 = new FieldLayoutTab(['name' => 'Advanced']);
        $tab2->setLayout($fieldLayout);

        $linkTitleField = $enableTitle ? Craft::createObject([
            'class' => LinkTitleField::class,
        ]) : null;

        $classesField = Craft::createObject([
            'class' => ClassesField::class,
        ]);

        $customAttributesField = Craft::createObject([
            'class' => CustomAttributesField::class,
        ]);

        $ariaLabelField = $enableAriaLabel ? Craft::createObject([
            'class' => AriaLabelField::class,
        ]) : null;
        
        $tab2->setElements(array_filter([$linkTitleField, $classesField, $customAttributesField, $ariaLabelField]));

        $fieldLayout->setTabs([$tab1, $tab2]);

        return $fieldLayout;
    }

    public static function getLinkTypeHandle(array $linkTypes, string $handle): string
    {
        // Ensure that we generate a unique link type handle, as when migrating, we can have multiple
        // link types of the same type (LinkIt Twitter = Hyper URL).
        foreach ($linkTypes as $linkType) {
            if ($linkType['handle'] === $handle) {
                return StringHelper::randomString(10);
            }
        }

        return $handle;
    }

    public static function createDisabledLinkTypes(array &$linkTypes, array $disabledTypes): void
    {
        foreach ($disabledTypes as $linkTypeClass) {
            $linkType = new $linkTypeClass();
            $linkType->label = $linkType::displayName();
            $linkType->handle = self::getLinkTypeHandle($linkTypes, 'default-' . StringHelper::toKebabCase($linkTypeClass));
            $linkType->enabled = false;

            $fieldLayout = self::getDefaultFieldLayout(true);
            $linkType->layoutUid = StringHelper::UUID();
            $linkType->layoutConfig = $fieldLayout->getConfig();

            $linkTypes[] = $linkType->getSettingsConfig();
        }
    }

    public static function getClassDisplayName(string $class): string
    {
        $classNameParts = explode('\\', $class);

        return array_pop($classNameParts);
    }
}
