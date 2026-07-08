<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\LinkInterface;
use verbb\hyper\events\ModifyMigrationLinkEvent;
use verbb\hyper\fieldlayoutelements\AriaLabelField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\ArrayHelper;

use Craft;
use craft\db\Migration;
use craft\helpers\Json;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use yii\console\Controller;
use yii\helpers\Markdown;

use verbb\vizy\Vizy;

class PluginMigration extends Migration
{
    // Constants
    // =========================================================================

    public const EVENT_MODIFY_LINK_TYPE = 'modifyLinkType';


    // Properties
    // =========================================================================

    public bool $resaveFields = true;
    public array $fields = [];
    public string $oldFieldTypeClass = '';

    private ?Controller $_consoleRequest = null;


    // Public Methods
    // =========================================================================

    public function safeDown(): bool
    {
        return false;
    }

    public function setConsoleRequest($value): void
    {
        $this->_consoleRequest = $value;
    }

    public function getLinkType($oldClass): ?string
    {
        $newClass = $this->typeMap[$oldClass] ?? null;

        // Fire a 'modifyLinkType' event
        $event = new ModifyMigrationLinkEvent([
            'oldClass' => $oldClass,
            'newClass' => $newClass,
        ]);
        $this->trigger(self::EVENT_MODIFY_LINK_TYPE, $event);

        return $event->newClass;
    }

    public static function getDefaultFieldLayout(LinkInterface $linkType, bool $includeText = true, bool $enableTitle = true, bool $enableAriaLabel = false): FieldLayout
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
        
        $tab2->setElements(array_filter([$linkTitleField, $classesField, $customAttributesField, $ariaLabelField]));

        $fieldLayout->setTabs([$tab1, $tab2]);

        return $fieldLayout;
    }

    public function prepLinkTypes(HyperField $field): void
    {
        $linkTypes = [];

        foreach ($field->linkTypes as $linkType) {
            $linkTypes[] = $linkType->getSettingsConfig();
        }

        $field->linkTypes = $linkTypes;
    }

    public function migrateVizyContent($fieldData): void
    {
        Vizy::$plugin->getContent()->modifyFieldContent($fieldData['uid'], $fieldData['handle'], function($handle, $data) {
            // We need to flatten the data to deal with deeply-nested content like when in Matrix/Super Table.
            foreach (ArrayHelper::flatten($data) as $flatKey => $flatContent) {
                // Only consider values stored directly against a block field. Craft 4 Vizy keyed these by
                // the field handle (`fields.myLinkField`), but Craft 5 keys them by the field-layout element
                // UID (`fields.<uid>`), so we can't rely on the handle alone to locate the content.
                $marker = 'content.fields.';
                $markerPos = strrpos($flatKey, $marker);

                if ($markerPos === false) {
                    continue;
                }

                // Grab the trailing key segment (handle or UID) and skip nested paths within a field value.
                $contentKey = substr($flatKey, $markerPos + strlen($marker));

                if (str_contains($contentKey, '.')) {
                    continue;
                }

                // Sometimes stored as a JSON string
                if (is_string($flatContent)) {
                    $flatContent = Json::decodeIfJson($flatContent);
                }

                if (!is_array($flatContent)) {
                    continue;
                }

                // Match either by the field handle (legacy handle-keyed content) or by detecting the old
                // link value shape (UID-keyed Craft 5 content, where we can't resolve the layout element UID).
                $matchesHandle = ($contentKey === $handle);

                if (!$matchesHandle && !$this->isMigratableVizyValue($flatContent)) {
                    continue;
                }

                if ($newContent = $this->convertModel(new HyperField(), $flatContent)) {
                    ArrayHelper::setValue($data, $flatKey, $newContent);
                }
            }

            return $data;
        }, $this->db);
    }

    /**
     * Detects whether a Vizy block field content value looks like an un-migrated value for the plugin
     * being migrated. Used to locate content when Craft 5 keys Vizy block fields by the field-layout
     * element UID (rather than the field handle), so we can't match by handle alone. Subclasses should
     * override this with a check specific to their old field/value shape.
     */
    protected function isMigratableVizyValue(array $value): bool
    {
        return false;
    }

    public function isPluginInstalledAndEnabled(string $plugin): bool
    {
        $pluginsService = Craft::$app->getPlugins();

        // Ensure that we check if initialized, installed and enabled. 
        // The plugin might be installed but disabled, or installed and enabled, but missing plugin files.
        return $pluginsService->isPluginInstalled($plugin) && $pluginsService->isPluginEnabled($plugin) && $pluginsService->getPlugin($plugin);
    }

    public function stdout($string, $color = ''): void
    {
        if ($this->_consoleRequest) {
            $this->_consoleRequest->stdout($string . PHP_EOL, $color);
        } else {
            $class = '';

            if ($color) {
                $class = 'color-' . $color;
            }

            echo '<div class="log-label ' . $class . '">' . Markdown::processParagraph($string) . '</div>';
        }
    }

    public function getExceptionTraceAsString($exception): string
    {
        $rtn = "";
        $count = 0;

        foreach ($exception->getTrace() as $frame) {
            $args = "";

            if (isset($frame['args'])) {
                $args = [];

                foreach ($frame['args'] as $arg) {
                    if (is_string($arg)) {
                        $args[] = "'" . $arg . "'";
                    } else if (is_array($arg)) {
                        $args[] = "Array";
                    } else if (is_null($arg)) {
                        $args[] = 'NULL';
                    } else if (is_bool($arg)) {
                        $args[] = ($arg) ? "true" : "false";
                    } else if (is_object($arg)) {
                        $args[] = get_class($arg);
                    } else if (is_resource($arg)) {
                        $args[] = get_resource_type($arg);
                    } else {
                        $args[] = $arg;
                    }
                }

                $args = implode(", ", $args);
            }

            $rtn .= sprintf("#%s %s(%s): %s(%s)\n",
                $count,
                $frame['file'] ?? '[internal function]',
                $frame['line'] ?? '',
                (isset($frame['class'])) ? $frame['class'] . $frame['type'] . $frame['function'] : $frame['function'],
                $args);

            $count++;
        }

        return $rtn;
    }
}
