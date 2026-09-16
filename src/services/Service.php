<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\Plugin;

use Craft;
use craft\base\Component;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
use craft\events\ApplyFieldSaveEvent;
use craft\events\ConfigEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\models\FieldLayout;

use yii\base\InvalidConfigException;

class Service extends Component
{
    // Properties
    // =========================================================================

    private array $_layoutsByUid = [];


    // Public Methods
    // =========================================================================

    public function handleChangedField(ConfigEvent $event): void
    {
        $data = $event->newValue ?? [];

        if (!is_array($data)) {
            return;
        }

        // This handler fires on every field-change, so we need to ensure this field is a Hyper field.
        // We want to watch for Hyper field changes to update each block type's field layout.
        if ($data['type'] !== HyperField::class) {
            return;
        }

        $linkTypes = $data['settings']['linkTypes'] ?? [];
        $linkTypeConfig = $data['settings']['linkTypeConfig'] ?? null;
        $isCustom = $linkTypeConfig === LinkTypeConfigs::CUSTOM_HANDLE;

        // Shared-config fields store empty linkTypes; layouts live under plugins.hyper.linkTypeConfigs.
        if (!$isCustom || !$linkTypes) {
            return;
        }

        $linkTypes = ProjectConfigHelper::unpackAssociativeArrays($linkTypes);
        $this->saveField($linkTypes, $event);
    }

    public function handleBeforeApplyFieldSave(ApplyFieldSaveEvent $event): void
    {
        $data = $event->config;

        if (($data['type'] ?? null) !== HyperField::class) {
            return;
        }

        $settings = ProjectConfigHelper::unpackAssociativeArrays($data['settings'] ?? []);

        if (($settings['linkTypeConfig'] ?? null) === LinkTypeConfigs::CUSTOM_HANDLE) {
            // Craft writes the field row before Hyper's project-config listener runs.
            $this->_createValidatedLayouts($settings['linkTypes'] ?? []);
        }
    }

    public function createFieldLayout(array $linkType): ?FieldLayout
    {
        $layoutConfig = $linkType['layoutConfig'] ?? [];

        if (!$layoutConfig) {
            return null;
        }

        ArrayHelper::remove($layoutConfig, 'uid');

        // Older project config can contain the pre-Craft 5.8 array representation.
        if (isset($layoutConfig['cardThumbAlignment']) && is_array($layoutConfig['cardThumbAlignment'])) {
            $layoutConfig['cardThumbAlignment'] = reset($layoutConfig['cardThumbAlignment']);
        }

        $layout = FieldLayout::createFromConfig($layoutConfig);
        $layout->type = $linkType['type'];
        $layout->uid = $linkType['layoutUid'] ?? null;

        return $layout;
    }

    public function saveField(array $linkTypes, ?ConfigEvent $event = null): void
    {
        $layouts = $this->_createValidatedLayouts($linkTypes);

        Craft::$app->getDb()->transaction(function() use ($layouts) {
            foreach ($layouts as $layout) {
                if (!Craft::$app->getFields()->saveLayout($layout)) {
                    throw new InvalidConfigException(implode(' ', $layout->getErrorSummary(true)));
                }
            }
        });
    }

    public function handleDeletedField(ConfigEvent $event): void
    {
        $data = $event->oldValue ?? [];

        $fieldsService = Craft::$app->getFields();

        if (!is_array($data)) {
            return;
        }

        // This handler fires on every field-change, so we need to ensure this field is a Hyper field.
        // We want eo watch for Hyper field changes to update each block type's field layout.
        if ($data['type'] !== HyperField::class) {
            return;
        }

        $linkTypes = $data['settings']['linkTypes'] ?? [];
        $linkTypes = ProjectConfigHelper::unpackAssociativeArrays($linkTypes);

        foreach ($linkTypes as $linkType) {
            $layoutUid = $linkType['layoutUid'] ?? '';

            // Add an extra check in here to ensure the layout exists, before deleting it. Deleting via ID may throw an error
            // if the field layout doesn't exist.
            if ($layout = $fieldsService->getLayoutByUid($layoutUid)) {
                $fieldsService->deleteLayout($layout);
            }
        }
    }

    public function handleChangedBlockType(ConfigEvent $event): void
    {
        $fields = $event->newValue['fields'] ?? [];

        foreach ($fields as $field) {
            if ($field['type'] === HyperField::class) {
                $configEvent = new ConfigEvent([
                    'newValue' => $field,
                ]);

                // Call the regular event handler with a fake event to prevent duplicate code
                $this->handleChangedField($configEvent);
            }
        }
    }

    public function handleDeletedBlockType(ConfigEvent $event): void
    {
        $fields = $event->oldValue['fields'] ?? [];

        foreach ($fields as $field) {
            if ($field['type'] === HyperField::class) {
                $configEvent = new ConfigEvent([
                    'oldValue' => $field,
                ]);

                // Call the regular event handler with a fake event to prevent duplicate code
                $this->handleDeletedField($configEvent);
            }
        }
    }

    public function isPluginInstalledAndEnabled(string $pluginHandle): bool
    {
        return Plugin::isPluginInstalledAndEnabled($pluginHandle);
    }

    public function getRelatedElementsQuery(array $params = []): ?ElementQueryInterface
    {
        return Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery($params);
    }


    // Private Methods
    // =========================================================================

    private function _createValidatedLayouts(array $linkTypes): array
    {
        $layouts = [];

        foreach ($linkTypes as $linkType) {
            if (empty($linkType['layoutUid'])) {
                continue;
            }

            $layout = $this->createFieldLayout($linkType);

            if (!$layout) {
                continue;
            }

            if (!$layout->validate()) {
                throw new InvalidConfigException(implode(' ', $layout->getErrorSummary(true)));
            }

            $layouts[] = $layout;
        }

        return $layouts;
    }
}
