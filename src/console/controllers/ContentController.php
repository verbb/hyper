<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\content\ContentRef;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

use Craft;
use craft\helpers\Console;
use craft\helpers\Json;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Bulk content operations for Hyper fields.
 */
class ContentController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @var string The Hyper field handle to inspect or modify.
     */
    public string $field = '';

    /**
     * @var bool Whether to report matches without writing changes.
     */
    public bool $dryRun = true;

    /**
     * @var bool Whether to include Hyper fields nested in Matrix, Vizy, Super Table, and Neo.
     */
    public bool $includeNested = true;

    /**
     * @var string|null Comma-separated element IDs to scope the operation.
     */
    public ?string $elementIds = null;

    /**
     * @var string|null Substring the raw content JSON must contain (SQL LIKE prefilter).
     */
    public ?string $contentContains = null;


    // Public Methods
    // =========================================================================

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'field';
        $options[] = 'dryRun';
        $options[] = 'includeNested';
        $options[] = 'elementIds';
        $options[] = 'contentContains';

        return $options;
    }

    /**
     * Runs a content transform against all instances of a Hyper field.
     *
     * By default this is a dry run. Pass `--dry-run=0` to write changes.
     *
     * Example:
     * ./craft hyper/content/modify --field=navLinks --dry-run=1
     */
    public function actionModify(): int
    {
        if ($this->field === '') {
            $this->stderr("--field is required.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $field = Craft::$app->getFields()->getFieldByHandle($this->field);

        if (!$field instanceof HyperField) {
            $this->stderr("Hyper field “{$this->field}” not found.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $elementIds = null;

        // Only an omitted filter selects all owners; explicit empty/zero input selects none.
        if ($this->elementIds !== null) {
            $elementIds = array_values(array_filter(array_map(
                static fn(string $id): int => (int)trim($id),
                explode(',', $this->elementIds),
            )));
        }

        $options = new ModifyOptions(
            dryRun: (bool)$this->dryRun,
            syncRelations: !$this->dryRun,
            includeNested: (bool)$this->includeNested,
            elementIds: $elementIds,
            contentContains: $this->contentContains,
            persistTransformedValues: true,
        );

        $this->stdout(sprintf(
            "Scanning Hyper field “%s” (%s)%s...\n",
            $field->handle,
            $field->uid,
            $options->dryRun ? ' [dry run]' : '',
        ));

        // Links are left as they are. A copy is returned only for values stored in an older
        // format, so that just those are re-saved in the current one.
        $result = Hyper::$plugin->getContent()->modify(
            $field,
            static fn(LinkCollection $collection, ContentRef $ref): LinkCollection => self::_isStoredInCurrentFormat($ref->value, $collection) ? $collection : clone $collection,
            $options,
        );

        $changed = $result->getChangedCount($options->dryRun);

        $this->stdout(sprintf(
            "Matched: %d\nWould modify / modified: %d\n",
            $result->matched,
            $changed,
        ), $changed ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Rebuild hyper_links rows from canonical owner JSON for a Hyper field.
     *
     * Example:
     * ./craft hyper/content/sync-relations --field=navLinks --dry-run=0
     */
    public function actionSyncRelations(): int
    {
        if ($this->field === '') {
            $this->stderr("--field is required.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $field = Craft::$app->getFields()->getFieldByHandle($this->field);

        if (!$field instanceof HyperField) {
            $this->stderr("Hyper field “{$this->field}” not found.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $elementIds = null;

        // Only an omitted filter selects all owners; explicit empty/zero input selects none.
        if ($this->elementIds !== null) {
            $elementIds = array_values(array_filter(array_map(
                static fn(string $id): int => (int)trim($id),
                explode(',', $this->elementIds),
            )));
        }

        $options = new ModifyOptions(
            dryRun: (bool)$this->dryRun,
            // Always request sync when writing; identity transform still forces index rebuild.
            syncRelations: !$this->dryRun,
            includeNested: (bool)$this->includeNested,
            elementIds: $elementIds,
            contentContains: $this->contentContains,
        );

        $this->stdout(sprintf(
            "Reconciling hyper_links for “%s”%s...\n",
            $field->handle,
            $options->dryRun ? ' [dry run]' : '',
        ));

        // Force a no-op content rewrite that still records modifications when syncRelations
        // needs owners — Content::modify skips identical encodes, so nudge via sync-only path.
        $synced = Hyper::$plugin->getContent()->reconcileRelations($field, $options);

        $this->stdout(sprintf("Owners reconciled: %d\n", $synced), Console::FG_GREEN);

        return ExitCode::OK;
    }


    // Private Methods
    // =========================================================================

    private static function _isStoredInCurrentFormat(mixed $stored, LinkCollection $collection): bool
    {
        // MySQL reorders the keys of stored JSON objects, so key order is not a difference.
        return Json::encode(self::_sortKeys(ElementContentStore::decodeStored($stored)))
            === Json::encode(self::_sortKeys($collection->serializeValues()));
    }

    private static function _sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::_sortKeys(...), $value);
    }
}
