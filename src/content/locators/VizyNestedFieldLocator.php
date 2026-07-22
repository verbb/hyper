<?php
namespace verbb\hyper\content\locators;

use Craft;
use craft\base\FieldInterface;
use craft\models\FieldLayout;

class VizyNestedFieldLocator extends FieldsMapNestedFieldLocator
{
    // Public Methods
    // =========================================================================

    public static function hostFieldClass(): string
    {
        return \verbb\vizy\fields\VizyField::class;
    }


    // Protected Methods
    // =========================================================================

    protected function _getNestedLayouts(FieldInterface $hostField): iterable
    {
        if (!class_exists(\verbb\vizy\fields\VizyField::class) || !$hostField instanceof \verbb\vizy\fields\VizyField) {
            return;
        }

        if (method_exists($hostField, 'getBlockTypes')) {
            foreach ($hostField->getBlockTypes() as $blockType) {
                if ($layout = $blockType->getFieldLayout()) {
                    yield $layout;
                }
            }

            return;
        }

        foreach ($this->_getBlockTypeConfigs($hostField) as $blockType) {
            if ($layout = $this->_resolveBlockTypeLayout($blockType)) {
                yield $layout;
            }
        }
    }

    protected function _shouldRecurseIntoChild(mixed $key, array $child): bool
    {
        if ($key === 'data' || $key === 'blocks') {
            return true;
        }

        return parent::_shouldRecurseIntoChild($key, $child);
    }


    // Private Methods
    // =========================================================================

    private function _getBlockTypeConfigs(FieldInterface $hostField): array
    {
        $settings = $hostField->getSettings();

        if (!is_array($settings)) {
            return [];
        }

        foreach (['blockTypes', 'groups', 'vizyConfig.blockTypes'] as $key) {
            if ($key === 'vizyConfig.blockTypes') {
                $blockTypes = $settings['vizyConfig']['blockTypes'] ?? null;
            } else {
                $blockTypes = $settings[$key] ?? null;
            }

            if (is_array($blockTypes) && $blockTypes !== []) {
                return array_values($blockTypes);
            }
        }

        return [];
    }

    private function _resolveBlockTypeLayout(array $blockType): ?FieldLayout
    {
        $layoutConfig = $blockType['fieldLayout'] ?? $blockType['layoutConfig'] ?? null;

        if (is_array($layoutConfig)) {
            $layout = new FieldLayout(['type' => \verbb\vizy\fields\VizyField::class]);
            Craft::$app->getFields()->assembleLayout($layout, $layoutConfig, false);

            return $layout;
        }

        $layoutUid = $blockType['fieldLayoutUid'] ?? $blockType['layoutUid'] ?? null;

        if (is_string($layoutUid) && $layoutUid !== '') {
            return Craft::$app->getFields()->getLayoutByUid($layoutUid);
        }

        return null;
    }
}
