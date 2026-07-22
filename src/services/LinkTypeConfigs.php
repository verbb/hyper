<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\helpers\StringHelper;
use verbb\hyper\links as linkTypes;
use verbb\hyper\models\LinkTypeConfig;
use verbb\hyper\models\LinkTypeDefinition;

use Craft;
use craft\base\Component;
use craft\events\ConfigEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

class LinkTypeConfigs extends Component
{
    // Constants
    // =========================================================================

    public const PROJECT_CONFIG_PATH = 'plugins.hyper.linkTypeConfigs';

    public const DEFAULT_HANDLE = 'default';
    public const CUSTOM_HANDLE = 'custom';


    // Properties
    // =========================================================================

    private array $_configs = [];
    private bool $_configsLoaded = false;
    private array $_hydratedByHandle = [];


    // Public Methods
    // =========================================================================

    public function ensureConfigsExist(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->getIsApplyingExternalChanges()) {
            return;
        }

        $existing = $projectConfig->get(self::PROJECT_CONFIG_PATH);

        if (is_array($existing) && $existing !== []) {
            return;
        }

        $config = new LinkTypeConfig([
            'name' => Craft::t('hyper', 'Default'),
            'handle' => self::DEFAULT_HANDLE,
            'linkTypes' => $this->createStockSerializedLinkTypes(),
        ]);

        $this->saveConfig($config);
    }

    public function getAllConfigs(): array
    {
        $this->_loadConfigs();

        $configs = array_values($this->_configs);
        usort($configs, static fn(LinkTypeConfig $a, LinkTypeConfig $b): int =>
            [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]
        );

        return $configs;
    }

    public function getConfigByUid(?string $uid): ?LinkTypeConfig
    {
        if (!$uid) {
            return null;
        }

        $this->_loadConfigs();

        return $this->_configs[$uid] ?? null;
    }

    public function getConfigByHandle(?string $handle): ?LinkTypeConfig
    {
        if (!$handle) {
            return null;
        }

        foreach ($this->getAllConfigs() as $config) {
            if ($config->handle === $handle) {
                return $config;
            }
        }

        return null;
    }

    public function getDefaultConfig(): LinkTypeConfig
    {
        $this->ensureConfigsExist();

        return $this->getConfigByHandle(self::DEFAULT_HANDLE)
            ?? $this->getAllConfigs()[0]
            ?? new LinkTypeConfig([
                'name' => Craft::t('hyper', 'Default'),
                'handle' => self::DEFAULT_HANDLE,
                'linkTypes' => $this->createStockSerializedLinkTypes(),
            ]);
    }

    public function resolveConfig(?string $handle): LinkTypeConfig
    {
        return $this->getConfigByHandle($handle) ?? $this->getDefaultConfig();
    }

    public function getSerializedLinkTypes(?string $handle = null): array
    {
        return $this->resolveConfig($handle)->linkTypes;
    }

    public function getLinkTypes(?string $handle = null): array
    {
        $config = $this->resolveConfig($handle);
        $cacheKey = $config->handle ?: $config->uid ?: 'default';

        if (isset($this->_hydratedByHandle[$cacheKey])) {
            return $this->_hydratedByHandle[$cacheKey];
        }

        $registeredLinkTypes = Hyper::$plugin->getLinks()->getAllLinkTypes();
        $hydrated = [];

        foreach ($config->linkTypes as $key => $linkTypeConfig) {
            if (!is_array($linkTypeConfig)) {
                continue;
            }

            if ($registeredLinkTypes && !in_array($linkTypeConfig['type'] ?? null, $registeredLinkTypes, true)) {
                continue;
            }

            $sortOrder = ArrayHelper::remove($linkTypeConfig, 'sortOrder', $key);
            $linkType = Hyper::$plugin->getLinks()->createSettingsPrototype($linkTypeConfig);

            if (!$linkType->layoutConfig) {
                $linkType->layoutConfig = $linkType::getDefaultFieldLayout()->getConfig();
            }

            if (!$linkType->layoutUid) {
                $linkType->layoutUid = StringHelper::UUID();
            }

            $hydrated[$sortOrder] = $linkType;
        }

        $this->_hydratedByHandle[$cacheKey] = $hydrated;

        return $hydrated;
    }

    public function getLinkTypeDefinitions(?string $handle = null): array
    {
        return $this->resolveConfig($handle)->getLinkTypeDefinitions();
    }

    public function createDetachedCopy(?string $handle = null): array
    {
        $copies = [];

        foreach ($this->getSerializedLinkTypes($handle) as $config) {
            if (!is_array($config)) {
                continue;
            }

            $config['layoutUid'] = StringHelper::UUID();
            $copies[] = $config;
        }

        return $copies;
    }

    public function createStockSerializedLinkTypes(): array
    {
        $disabledTypes = [
            linkTypes\Asset::class,
            linkTypes\Custom::class,
            linkTypes\Embed::class,
            linkTypes\Passive::class,
            linkTypes\Phone::class,
            linkTypes\Site::class,
            linkTypes\User::class,
        ];

        $registeredLinkTypes = Hyper::$plugin->getLinks()->getAllLinkTypes();
        sort($registeredLinkTypes);

        $linkTypes = [];

        foreach ($registeredLinkTypes as $linkTypeClass) {
            $linkType = Hyper::$plugin->getLinks()->createSettingsPrototype($linkTypeClass);
            $linkType->label = $linkType->label ?? $linkTypeClass::displayName();
            // Stock types are the built-in single instances, so they take the short type key.
            $linkType->handle = $linkType->handle ?? $linkTypeClass::typeKey();
            $linkType->enabled = !in_array($linkTypeClass, $disabledTypes, true);

            if (!$linkType->layoutConfig) {
                $linkType->layoutConfig = $linkTypeClass::getDefaultFieldLayout()->getConfig();
            }

            if (!$linkType->layoutUid) {
                $linkType->layoutUid = StringHelper::UUID();
            }

            $linkTypes[] = $linkType->getSettingsConfigForDb();
        }

        return $linkTypes;
    }

    public static function normalizeLegacyStockHandles(array $linkTypes): array
    {
        foreach ($linkTypes as &$linkType) {
            if (!is_array($linkType) || !empty($linkType['isCustom'])) {
                continue;
            }

            $type = $linkType['type'] ?? null;

            if (!is_string($type) || !is_subclass_of($type, \verbb\hyper\base\Link::class)) {
                continue;
            }

            $legacyHandle = 'default-' . StringHelper::toKebabCase($type);

            if (($linkType['handle'] ?? null) === $legacyHandle) {
                $linkType['handle'] = $type::typeKey();
            }
        }
        unset($linkType);

        return $linkTypes;
    }

    public function saveConfig(LinkTypeConfig $config, ?string $uid = null): bool
    {
        $isNew = !$this->getConfigByUid($config->uid);

        if (!$config->uid) {
            $config->uid = StringHelper::UUID();
        }

        if (!$config->handle) {
            $config->handle = \craft\helpers\StringHelper::toHandle($config->name);
        }

        // Normalize link type payloads before PC write.
        $normalized = [];

        foreach ($config->linkTypes as $inputOrder => $linkType) {
            $sortOrder = is_int($inputOrder) ? $inputOrder : count($normalized);

            if ($linkType instanceof LinkInterface) {
                $prototype = $linkType;
            } elseif (is_array($linkType)) {
                // The configurator posts ordering metadata alongside model attributes.
                // Consume it here so Yii never attempts to set it on the link type.
                $sortOrder = (int)ArrayHelper::remove($linkType, 'sortOrder', $sortOrder);
                $prototype = Hyper::$plugin->getLinks()->createSettingsPrototype($linkType);
            } else {
                continue;
            }

            if (!$prototype->layoutConfig) {
                $prototype->layoutConfig = $prototype::getDefaultFieldLayout()->getConfig();
            }

            if (!$prototype->layoutUid) {
                $prototype->layoutUid = StringHelper::UUID();
            }

            $normalized[$sortOrder] = $prototype->getSettingsConfigForDb();
        }

        if (!$normalized) {
            $normalized = $this->createStockSerializedLinkTypes();
        } else {
            ksort($normalized);
            $normalized = array_values($normalized);
        }

        $config->linkTypes = $normalized;

        if ($isNew && !$config->sortOrder) {
            $config->sortOrder = array_reduce(
                $this->getAllConfigs(),
                static fn(int $max, LinkTypeConfig $existing): int => max($max, $existing->sortOrder),
                0,
            ) + 1;
        }

        if (!$config->validate()) {
            return false;
        }

        // `custom` is reserved for field-owned link type settings.
        if ($config->handle === self::CUSTOM_HANDLE) {
            $config->addError('handle', Craft::t('hyper', 'Handle “{handle}” is reserved.', [
                'handle' => self::CUSTOM_HANDLE,
            ]));

            return false;
        }

        // Unique handle among siblings.
        foreach ($this->getAllConfigs() as $other) {
            if ($other->uid !== $config->uid && $other->handle === $config->handle) {
                $config->addError('handle', Craft::t('hyper', 'Handle “{handle}” is already in use.', [
                    'handle' => $config->handle,
                ]));

                return false;
            }
        }

        $uid = $uid ?: $config->uid;
        $payload = ProjectConfigHelper::packAssociativeArrays($config->toConfig());

        Craft::$app->getProjectConfig()->set(self::PROJECT_CONFIG_PATH . '.' . $uid, $payload);

        $this->_resetCache();

        return true;
    }

    public function reorderConfigs(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach (array_values($uids) as $index => $uid) {
            if ($this->getConfigByUid((string)$uid)) {
                $projectConfig->set(
                    self::PROJECT_CONFIG_PATH . '.' . $uid . '.sortOrder',
                    $index + 1,
                    'Reorder Hyper link type configs',
                );
            }
        }

        $this->_resetCache();

        return true;
    }

    public function deleteConfig(string $uid): void
    {
        $config = $this->getConfigByUid($uid);

        if (!$config || !$config->canDelete()) {
            return;
        }

        Craft::$app->getProjectConfig()->remove(self::PROJECT_CONFIG_PATH . '.' . $uid);

        $this->_resetCache();
    }

    public function handleChangedConfig(ConfigEvent $event): void
    {
        $config = $event->newValue ?? [];

        if (!is_array($config)) {
            return;
        }

        $config = ProjectConfigHelper::unpackAssociativeArrays($config);
        $linkTypes = $config['linkTypes'] ?? [];

        if (is_array($linkTypes)) {
            Hyper::$plugin->getService()->saveField($linkTypes, $event);
        }

        $this->_resetCache();
    }

    public function handleDeletedConfig(ConfigEvent $event): void
    {
        $config = $event->oldValue ?? [];

        if (!is_array($config)) {
            return;
        }

        $config = ProjectConfigHelper::unpackAssociativeArrays($config);
        $linkTypes = $config['linkTypes'] ?? [];
        $fieldsService = Craft::$app->getFields();

        foreach ($linkTypes as $linkType) {
            $layoutUid = $linkType['layoutUid'] ?? '';

            if ($layoutUid && ($layout = $fieldsService->getLayoutByUid($layoutUid))) {
                $fieldsService->deleteLayout($layout);
            }
        }

        $this->_resetCache();
    }


    // Private Methods
    // =========================================================================

    private function _loadConfigs(): void
    {
        if ($this->_configsLoaded) {
            return;
        }

        $this->_configsLoaded = true;
        $this->_configs = [];

        $all = Craft::$app->getProjectConfig()->get(self::PROJECT_CONFIG_PATH) ?? [];

        if (!is_array($all)) {
            return;
        }

        foreach ($all as $uid => $configData) {
            if (!is_array($configData)) {
                continue;
            }

            $configData['uid'] = $configData['uid'] ?? $uid;
            $configData['linkTypes'] = self::normalizeLegacyStockHandles($configData['linkTypes'] ?? []);
            $this->_configs[$uid] = LinkTypeConfig::fromConfig($configData);
        }
    }

    private function _resetCache(): void
    {
        $this->_configsLoaded = false;
        $this->_configs = [];
        $this->_hydratedByHandle = [];
    }
}
