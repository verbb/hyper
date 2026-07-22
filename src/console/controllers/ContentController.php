<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\content\ModifyOptions;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\models\LinkCollection;

use Craft;
use craft\helpers\Console;

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

        if ($this->elementIds) {
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
        );

        $this->stdout(sprintf(
            "Scanning Hyper field “%s” (%s)%s...\n",
            $field->handle,
            $field->uid,
            $options->dryRun ? ' [dry run]' : '',
        ));

        $result = Hyper::$plugin->getContent()->modify(
            $field,
            static fn(LinkCollection $collection): LinkCollection => $collection,
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
}
