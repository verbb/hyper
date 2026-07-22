<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\AriaLabelField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\fieldlayoutelements\UrlSuffixField;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\services\Fields;

use Exception;

class PluginFieldMigration extends PluginMigration
{
    // Properties
    // =========================================================================

    public int $count = 0;


    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        App::maxPowerCaptain();

        $this->fields = (new Query())
            ->from('{{%fields}}')
            ->where(['type' => $this->getOldFieldTypeClasses()])
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

                if (!$this->saveFieldForMigration($fieldService, $field)) {
                    throw new Exception(Json::encode($field->getErrors()));
                }

                $this->stdout("    > Field “{$fieldData['handle']}” migration finalised." . PHP_EOL, Console::FG_GREEN);
            }

            // `saveField()` only writes `fields.*` project config for global fields. If a migration
            // converted a field stored elsewhere in project config, update that field definition in place.
            $this->syncMigratedFieldsToProjectConfig($fieldService);
        }

        if ($this->count) {
            $this->stdout('Finished migration, processed ' . $this->count . '/' . count($this->fields) . ' fields.' . PHP_EOL, Console::FG_GREEN);
        } else {
            $this->stdout('No fields available to migrate.' . PHP_EOL, Console::FG_GREEN);
        }

        return true;
    }

    protected function saveFieldForMigration(Fields $fieldService, mixed $field): bool
    {
        try {
            // Preserve normal validation so field-level issues are still reported.
            return $fieldService->saveField($field);
        } catch (\Throwable $e) {
            // Some field validation paths rely on web sessions and will always fail in console requests.
            // Fall back to a non-validating save only for this known, unavoidable console edge case.
            if (
                Craft::$app instanceof \craft\console\Application &&
                str_contains($e->getMessage(), 'Session does not exist in a console request')
            ) {
                $fieldHandle = $field->handle ?? 'unknown';
                $this->stdout("    > Field “{$fieldHandle}” triggered session-bound validation in console. Retrying without validation." . PHP_EOL, Console::FG_YELLOW);

                return $fieldService->saveField($field, false);
            }

            throw $e;
        }
    }

    public static function getDefaultFieldLayout(LinkInterface $linkType, bool $includeText = true, bool $enableTitle = true, bool $enableAriaLabel = false, bool $enableSuffix = false): FieldLayout
    {
        $fieldLayout = new FieldLayout([
            'type' => $linkType::class,
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

        $suffixField = $enableSuffix ? Craft::createObject([
            'class' => UrlSuffixField::class,
        ]) : null;
        
        $tab2->setElements(array_filter([$linkTitleField, $classesField, $customAttributesField, $ariaLabelField, $suffixField]));

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
            $linkType->handle = self::getLinkTypeHandle($linkTypes, $linkTypeClass::typeKey());
            $linkType->enabled = false;

            $fieldLayout = self::getDefaultFieldLayout($linkType, true);
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

    public static function normalizeElementLinkSources(mixed $sources): string|array
    {
        if ($sources === null || $sources === '' || $sources === []) {
            return '*';
        }

        if (is_array($sources)) {
            $filtered = array_values(array_filter($sources, static fn($s) => $s !== null && $s !== ''));

            if ($filtered === []) {
                return '*';
            }

            return $filtered;
        }

        return $sources;
    }

    protected function validateMigratedLinkTypeSettings(HyperField $newField, string $fieldHandle): bool
    {
        $ok = true;

        foreach ($newField->getLinkTypes() as $linkType) {
            $linkType->setScenario(Link::SCENARIO_SETTINGS);

            if (!$linkType->validate()) {
                $ok = false;
                $this->stdout(
                    '    > Field “' . $fieldHandle . '” (' . $linkType->handle . '): ' . Json::encode($linkType->getErrors()) . PHP_EOL,
                    Console::FG_RED,
                );
            }
        }

        return $ok;
    }

    protected function syncMigratedFieldsToProjectConfig(Fields $fieldService): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $config = $projectConfig->get();
        $synced = false;

        if (!is_array($config)) {
            return;
        }

        foreach ($this->fields as $fieldData) {
            $field = $fieldService->getFieldById($fieldData['id']);

            if (!$field instanceof HyperField) {
                continue;
            }

            $fieldConfig = $fieldService->createFieldConfig($field);

            foreach ($this->findMigratedFieldConfigPaths($config, $fieldData) as $path) {
                $currentConfig = $projectConfig->get($path);

                if (!is_array($currentConfig) || !in_array($currentConfig['type'] ?? null, $this->getOldFieldTypeClasses(), true)) {
                    continue;
                }

                $projectConfig->set($path, $fieldConfig, "Migrate field “{$field->handle}” to Hyper");
                $this->stdout("    > Project config synced for field “{$field->handle}” at `{$path}`." . PHP_EOL, Console::FG_GREEN);

                $synced = true;
            }
        }

        if ($synced) {
            $fieldService->refreshFields();
        }
    }

    protected function findMigratedFieldConfigPaths(array $config, array $fieldData, array $path = []): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $currentPath = [...$path, (string)$key];

            if ($this->isMigratedFieldConfig($value, (string)$key, $fieldData)) {
                $paths[] = implode('.', $currentPath);

                continue;
            }

            array_push($paths, ...$this->findMigratedFieldConfigPaths($value, $fieldData, $currentPath));
        }

        return $paths;
    }

    protected function isMigratedFieldConfig(array $config, string $key, array $fieldData): bool
    {
        if (!in_array($config['type'] ?? null, $this->getOldFieldTypeClasses(), true)) {
            return false;
        }

        if ($key === $fieldData['uid'] || ($config['uid'] ?? null) === $fieldData['uid']) {
            return true;
        }

        if (($config['handle'] ?? null) !== $fieldData['handle'] || !isset($config['settings'])) {
            return false;
        }

        $oldSettings = Json::decode($fieldData['settings']);
        $configSettings = ProjectConfigHelper::unpackAssociativeArrays($config['settings']);

        return $configSettings == $oldSettings;
    }
}
