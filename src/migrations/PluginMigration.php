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
use verbb\hyper\migrations\plugins\Line;
use verbb\hyper\migrations\plugins\MigrationResult;

use Craft;
use craft\db\Migration;
use craft\helpers\Console;
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
    public bool $dryRun = false;
    public bool $syncRelations = true;

    private ?Controller $_consoleRequest = null;
    private ?MigrationResult $_migrationResult = null;


    // Public Methods
    // =========================================================================

    public function safeDown(): bool
    {
        return false;
    }

    public function getOldFieldTypeClasses(): array
    {
        return $this->oldFieldTypeClass !== '' ? [$this->oldFieldTypeClass] : [];
    }

    public function setConsoleRequest($value): void
    {
        $this->_consoleRequest = $value;
    }

    public function setMigrationResult(?MigrationResult $result): void
    {
        $this->_migrationResult = $result;
    }

    public function getMigrationResult(): ?MigrationResult
    {
        return $this->_migrationResult;
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

    public function migrateVizyContent($fieldData, ?HyperField $field = null): void
    {
        if (!$this->isPluginInstalledAndEnabled('vizy')) {
            return;
        }

        // Dry-run must not invoke Vizy’s committing content mutation (Astra H3-A14).
        if ($this->dryRun) {
            $this->stdout('Skipping Vizy content mutation during dry-run.' . PHP_EOL);
            $this->getMigrationResult()?->addLine(
                Line::info('Vizy content skipped (dry-run).')
            );

            return;
        }

        $field ??= Craft::$app->getFields()->getFieldById($fieldData['id'] ?? 0);

        if (!$field instanceof HyperField) {
            $field = new HyperField();
        }

        Vizy::$plugin->getContent()->modifyFieldContent($fieldData['uid'], $fieldData['handle'], function($handle, $data) use ($field) {
            // Flatten to find deeply-nested paths (Matrix → Vizy → fields.{handle})
            foreach (ArrayHelper::flatten($data) as $flatKey => $flatContent) {
                $searchKey = 'fields.' . $handle;

                if (!str_ends_with((string)$flatKey, $searchKey)) {
                    continue;
                }

                if (is_string($flatContent)) {
                    // Decode JSON without HTML entity decoding
                    $decoded = json_decode($flatContent, true);
                    $flatContent = is_array($decoded) ? $decoded : (Json::decodeIfJson($flatContent) ?: []);
                }

                if (!is_array($flatContent)) {
                    $flatContent = [];
                }

                $converted = $this->convertModel($field, $flatContent);

                if (is_array($converted)) {
                    ArrayHelper::setValue($data, $flatKey, $converted);
                    $this->getMigrationResult()?->incrementStat('vizyBlocksMigrated');
                }
            }

            return $data;
        }, $this->db);
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
        $message = trim(strip_tags((string)$string));
        $depth = str_starts_with(ltrim((string)$string), '>') || preg_match('/^\s{2,}>?\s*/', (string)$string) ? 1 : 0;
        $level = match ($color) {
            Console::FG_GREEN, (string)Console::FG_GREEN, '32' => 'success',
            Console::FG_RED, (string)Console::FG_RED, '31' => 'error',
            Console::FG_YELLOW, (string)Console::FG_YELLOW, '33' => 'warning',
            default => 'info',
        };

        // Prefer structured result when the Navigation-style runner is attached
        if ($this->_migrationResult) {
            $line = match ($level) {
                'success' => Line::success($message, $depth),
                'error' => Line::error($message, $depth),
                'warning' => Line::warning($message, $depth),
                default => Line::info($message, $depth),
            };

            $this->_migrationResult->addLine($line);

            if ($level === 'error') {
                $this->_migrationResult->ok = false;
                $this->_migrationResult->incrementStat('errors');
            } elseif ($level === 'warning') {
                $this->_migrationResult->incrementStat('warnings');
            }

            // CP runners render the collected result after redirecting; emitting here
            // would send response content early and prevent those redirect headers.
            if (!$this->_consoleRequest) {
                return;
            }
        }

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
