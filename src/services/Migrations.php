<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\migrations\MigrateCraftLinkContent;
use verbb\hyper\migrations\MigrateCraftLinkField;
use verbb\hyper\migrations\MigrateLinkContent;
use verbb\hyper\migrations\MigrateLinkField;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateOembedContent;
use verbb\hyper\migrations\MigrateOembedField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use verbb\hyper\migrations\MigrateTypedLinkFieldLegacy;
use verbb\hyper\migrations\plugins\PluginMigrator;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\fields\Link as CraftLinkField;

class Migrations extends Component
{
    // Public Methods
    // =========================================================================

    public function getSources(): array
    {
        return [
            'typed-link' => $this->_sourceInfo(
                id: 'typed-link',
                label: Craft::t('hyper', 'Typed Link Field'),
                pluginHandle: 'typedlinkfield',
                oldFieldTypeClass: 'lenz\\linkfield\\fields\\LinkField',
                steps: [
                    [
                        'id' => 'legacy',
                        'label' => Craft::t('hyper', 'Update Legacy Fields'),
                        'migrationClass' => MigrateTypedLinkFieldLegacy::class,
                    ],
                    [
                        'id' => 'field',
                        'label' => Craft::t('hyper', 'Migrate Fields'),
                        'migrationClass' => MigrateTypedLinkField::class,
                    ],
                    [
                        'id' => 'content',
                        'label' => Craft::t('hyper', 'Migrate Content'),
                        'migrationClass' => MigrateTypedLinkContent::class,
                    ],
                ],
            ),
            'linkit' => $this->_sourceInfo(
                id: 'linkit',
                label: Craft::t('hyper', 'Linkit'),
                pluginHandle: 'linkit',
                oldFieldTypeClass: 'presseddigital\\linkit\\fields\\LinkitField',
                steps: [
                    [
                        'id' => 'field',
                        'label' => Craft::t('hyper', 'Migrate Fields'),
                        'migrationClass' => MigrateLinkitField::class,
                    ],
                    [
                        'id' => 'content',
                        'label' => Craft::t('hyper', 'Migrate Content'),
                        'migrationClass' => MigrateLinkitContent::class,
                    ],
                ],
            ),
            'link' => $this->_sourceInfo(
                id: 'link',
                label: Craft::t('hyper', 'Link (Flipbox)'),
                pluginHandle: 'link',
                // Current + pre-1.0 namespaces (nested Matrix/ST fields often still use legacy FQCN).
                oldFieldTypeClass: 'flipbox\\craft\\link\\fields\\Link',
                steps: [
                    [
                        'id' => 'field',
                        'label' => Craft::t('hyper', 'Migrate Fields'),
                        'migrationClass' => MigrateLinkField::class,
                    ],
                    [
                        'id' => 'content',
                        'label' => Craft::t('hyper', 'Migrate Content'),
                        'migrationClass' => MigrateLinkContent::class,
                    ],
                ],
                oldFieldTypeClasses: [
                    'flipbox\\craft\\link\\fields\\Link',
                    'flipbox\\link\\fields\\Link',
                ],
            ),
            'craft-link' => $this->_sourceInfo(
                id: 'craft-link',
                label: Craft::t('hyper', 'Craft Link'),
                pluginHandle: null,
                oldFieldTypeClass: CraftLinkField::class,
                steps: [
                    [
                        'id' => 'field',
                        'label' => Craft::t('hyper', 'Migrate Fields'),
                        'migrationClass' => MigrateCraftLinkField::class,
                    ],
                    [
                        'id' => 'content',
                        'label' => Craft::t('hyper', 'Migrate Content'),
                        'migrationClass' => MigrateCraftLinkContent::class,
                    ],
                ],
                // Native Craft field — ready when any Craft Link fields exist
                readyOverride: $this->_countFieldsByType(CraftLinkField::class) > 0,
            ),
            'oembed' => $this->_sourceInfo(
                id: 'oembed',
                label: Craft::t('hyper', 'oEmbed'),
                pluginHandle: 'oembed',
                oldFieldTypeClass: 'wrav\\oembed\\fields\\OembedField',
                steps: [
                    [
                        'id' => 'field',
                        'label' => Craft::t('hyper', 'Migrate Fields'),
                        'migrationClass' => MigrateOembedField::class,
                    ],
                    [
                        'id' => 'content',
                        'label' => Craft::t('hyper', 'Migrate Content'),
                        'migrationClass' => MigrateOembedContent::class,
                    ],
                ],
            ),
        ];
    }

    public function getSource(string $sourceId): ?array
    {
        return $this->getSources()[$sourceId] ?? null;
    }

    public function isSourceReady(string $sourceId): bool
    {
        return (bool)($this->getSource($sourceId)['ready'] ?? false);
    }

    public function getSourceSteps(string $sourceId): array
    {
        return $this->getSource($sourceId)['steps'] ?? [];
    }

    public function getStepMigrationClass(string $sourceId, string $stepId): ?string
    {
        foreach ($this->getSourceSteps($sourceId) as $step) {
            if ($step['id'] === $stepId) {
                return $step['migrationClass'];
            }
        }

        return null;
    }

    public function createMigrator(string $migrationClass, array $config = []): PluginMigrator
    {
        return Craft::createObject(array_merge([
            'class' => PluginMigrator::class,
            'migrationClass' => $migrationClass,
        ], $config));
    }


    // Private Methods
    // =========================================================================

    private function _sourceInfo(
        string $id,
        string $label,
        ?string $pluginHandle,
        string $oldFieldTypeClass,
        array $steps,
        ?bool $readyOverride = null,
        ?array $oldFieldTypeClasses = null,
    ): array {
        $installed = $pluginHandle
            ? Hyper::$plugin->getService()->isPluginInstalledAndEnabled($pluginHandle)
            : true;

        $types = $oldFieldTypeClasses ?? [$oldFieldTypeClass];
        $fieldCount = $this->_countFieldsByType($types);
        $ready = $readyOverride ?? ($installed && $fieldCount > 0);

        // Show CP tab when plugin is present (even if fields already migrated) or native fields remain
        $showInNav = $readyOverride !== null
            ? $readyOverride || $fieldCount > 0
            : $installed;

        return [
            'id' => $id,
            'label' => $label,
            'pluginHandle' => $pluginHandle,
            'oldFieldTypeClass' => $oldFieldTypeClass,
            'installed' => $installed,
            'fieldCount' => $fieldCount,
            'ready' => $ready,
            'showInNav' => $showInNav,
            'steps' => $steps,
            'consoleCommand' => 'hyper/migrate/' . $id,
        ];
    }

    private function _countFieldsByType(string|array $type): int
    {
        try {
            return (int)(new Query())
                ->from('{{%fields}}')
                ->where(['type' => $type])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
