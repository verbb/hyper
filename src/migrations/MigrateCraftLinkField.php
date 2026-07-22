<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\fields\Link as CraftLinkField;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

/**
 * Migrates Craft 5.3+ native `craft\fields\Link` field settings to Hyper.
 */
class MigrateCraftLinkField extends PluginFieldMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        'asset' => linkTypes\Asset::class,
        'category' => linkTypes\Category::class,
        'email' => linkTypes\Email::class,
        'entry' => linkTypes\Entry::class,
        'phone' => linkTypes\Phone::class,
        'tel' => linkTypes\Phone::class,
        'sms' => linkTypes\Url::class,
        'url' => linkTypes\Url::class,
        'product' => linkTypes\Product::class,
    ];

    public string $oldFieldTypeClass = CraftLinkField::class;


    // Public Methods
    // =========================================================================

    public function processFieldSettings(): void
    {
        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']) ?: [];
            $allowedTypes = $settings['types'] ?? ['entry', 'url'];
            $typeSettings = $settings['typeSettings'] ?? [];
            $showLabel = (bool)($settings['showLabelField'] ?? false);
            $advancedFields = $settings['advancedFields'] ?? [];

            if (!is_array($advancedFields)) {
                $advancedFields = [];
            }

            $enableTitle = in_array('title', $advancedFields, true);
            $enableAriaLabel = in_array('ariaLabel', $advancedFields, true);
            $enableUrlSuffix = in_array('urlSuffix', $advancedFields, true);
            $enableClasses = in_array('class', $advancedFields, true);
            $allowTarget = in_array('target', $advancedFields, true);

            $types = [];

            foreach ($allowedTypes as $typeId) {
                $typeId = (string)$typeId;
                $linkTypeClass = $this->getLinkType($typeId);

                if (!$linkTypeClass) {
                    $this->stdout("    > Skipping unknown Craft link type “{$typeId}”.", Console::FG_YELLOW);
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = $this->_labelForCraftType($typeId, $linkTypeClass);
                $linkType->handle = self::getLinkTypeHandle($types, $linkTypeClass::typeKey());
                $linkType->enabled = true;
                // A duplicate of the same kind was handed a random handle → it's a custom instance.
                $linkType->isCustom = $linkType->handle !== $linkTypeClass::typeKey();

                $craftTypeSettings = $typeSettings[$typeId] ?? [];

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = self::normalizeElementLinkSources($craftTypeSettings['sources'] ?? null);
                }

                $fieldLayout = self::getDefaultFieldLayout($linkType, $showLabel, $enableTitle, $enableAriaLabel);

                // Optionally add URL Suffix / Classes when Craft advanced fields were enabled
                if ($enableUrlSuffix || $enableClasses) {
                    $fieldLayout = $this->_withAdvancedNativeFields($fieldLayout, $enableUrlSuffix, $enableClasses);
                }

                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();
            }

            self::createDisabledLinkTypes($types, [
                linkTypes\Custom::class,
                linkTypes\Embed::class,
                linkTypes\FormieForm::class,
                linkTypes\Passive::class,
                linkTypes\ShopifyProduct::class,
                linkTypes\Site::class,
                linkTypes\Variant::class,
                linkTypes\User::class,
            ]);

            usort($types, fn($a, $b) => $a['label'] <=> $b['label']);

            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);

            $newFieldConfig['newWindow'] = $allowTarget;
            $newFieldConfig['linkTypes'] = $types;

            $newField = new HyperField($newFieldConfig);

            if (!$this->validateMigratedLinkTypeSettings($newField, $field['handle'])) {
                continue;
            }

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);
                continue;
            }

            $this->prepLinkTypes($newField);

            Db::update('{{%fields}}', [
                'type' => HyperField::class,
                'settings' => Json::encode($newField->settings),
            ], ['id' => $field['id']], [], true, $this->db);

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
            $this->count++;
        }
    }


    // Private Methods
    // =========================================================================

    private function _labelForCraftType(string $typeId, string $linkTypeClass): string
    {
        return match ($typeId) {
            'sms' => Craft::t('hyper', 'SMS'),
            'tel' => Craft::t('hyper', 'Phone'),
            default => $linkTypeClass::displayName(),
        };
    }

    private function _withAdvancedNativeFields(
        \craft\models\FieldLayout $fieldLayout,
        bool $enableUrlSuffix,
        bool $enableClasses,
    ): \craft\models\FieldLayout {
        $tabs = $fieldLayout->getTabs();
        $advanced = $tabs[1] ?? $tabs[0] ?? null;

        if (!$advanced) {
            return $fieldLayout;
        }

        $elements = $advanced->getElements();

        if ($enableUrlSuffix) {
            $elements[] = Craft::createObject([
                'class' => \verbb\hyper\fieldlayoutelements\UrlSuffixField::class,
            ]);
        }

        // ClassesField is already on the default Advanced tab — only ensure when missing
        if ($enableClasses) {
            $hasClasses = false;

            foreach ($elements as $element) {
                if ($element instanceof \verbb\hyper\fieldlayoutelements\ClassesField) {
                    $hasClasses = true;
                    break;
                }
            }

            if (!$hasClasses) {
                $elements[] = Craft::createObject([
                    'class' => \verbb\hyper\fieldlayoutelements\ClassesField::class,
                ]);
            }
        }

        $advanced->setElements($elements);

        return $fieldLayout;
    }
}
