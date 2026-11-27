<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\content\ContentRef;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Embed;
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

    private array $_fetchedEmbedData = [];


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
     * Re-fetches the metadata of Embed links whose stored embed data isn't signed by Hyper,
     * such as links saved by Hyper 1 or 2, so their embed HTML renders again.
     *
     * By default this is a dry run that makes no requests. Pass `--dry-run=0` to fetch and save.
     * Leave out `--field` to check every Hyper field with an Embed link type.
     *
     * Example:
     * ./craft hyper/content/refresh-embeds --field=videos --dry-run=0
     */
    public function actionRefreshEmbeds(): int
    {
        $fields = [];

        if ($this->field !== '') {
            $field = Craft::$app->getFields()->getFieldByHandle($this->field);

            if (!$field instanceof HyperField) {
                $this->stderr("Hyper field “{$this->field}” not found.\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $fields[] = $field;
        } else {
            foreach (Craft::$app->getFields()->getAllFields() as $field) {
                if ($field instanceof HyperField) {
                    $fields[] = $field;
                }
            }
        }

        $elementIds = null;

        // Only an omitted filter selects all owners; explicit empty/zero input selects none.
        if ($this->elementIds !== null) {
            $elementIds = array_values(array_filter(array_map(
                static fn(string $id): int => (int)trim($id),
                explode(',', $this->elementIds),
            )));
        }

        $dryRun = (bool)$this->dryRun;
        $totals = ['checked' => 0, 'signed' => 0, 'refreshed' => 0, 'failed' => 0];

        foreach ($fields as $field) {
            $hasEmbedType = false;

            foreach ($field->getLinkTypes() as $linkType) {
                if ($linkType instanceof Embed) {
                    $hasEmbedType = true;

                    break;
                }
            }

            if (!$hasEmbedType) {
                if ($this->field !== '') {
                    $this->stdout("Hyper field “{$field->handle}” has no Embed link types.\n");
                }

                continue;
            }

            $this->stdout(sprintf(
                "Checking Embed links in “%s”%s...\n",
                $field->handle,
                $dryRun ? ' [dry run]' : '',
            ));

            $options = new ModifyOptions(
                dryRun: true,
                syncRelations: false,
                includeNested: (bool)$this->includeNested,
                elementIds: $elementIds,
                contentContains: $this->contentContains,
            );

            if (!$dryRun) {
                // Fetch every URL found so far before writing, so the write transaction only fetches links saved since.
                $pending = [];
                $this->_refreshEmbedLinks($field, $options, $totals, $pending);

                foreach ($pending as [$link, $url]) {
                    $this->_fetchEmbedData($link, $url);
                }

                $options->dryRun = false;
                $options->syncRelations = true;
            }

            $this->_refreshEmbedLinks($field, $options, $totals);

            // Fetched metadata is only reused within a field, which keeps memory use bounded.
            $this->_fetchedEmbedData = [];
        }

        $this->stdout(sprintf(
            "Embed links checked: %d\nAlready signed: %d\n%s: %d\nFailed: %d\n",
            $totals['checked'],
            $totals['signed'],
            $dryRun ? 'Would refresh' : 'Refreshed',
            $totals['refreshed'],
            $totals['failed'],
        ), $totals['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

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

    /**
     * Visits every Embed link of a field. Passing `$pending` only collects the URLs to fetch.
     * Otherwise a dry run reports each link, and a write swaps in signed metadata from the fetch cache.
     */
    private function _refreshEmbedLinks(HyperField $field, ModifyOptions $options, array &$totals, ?array &$pending = null): void
    {
        $collect = $pending !== null;
        $write = !$collect && !$options->dryRun;

        Hyper::$plugin->getContent()->modify($field, function(LinkCollection $collection, ContentRef $ref) use ($collect, $write, &$totals, &$pending): LinkCollection {
            $links = $collection->getLinks();
            $changed = false;

            foreach ($links as $key => $link) {
                if (!$link instanceof Embed) {
                    continue;
                }

                $url = trim((string)$link->getLinkUrl());

                if ($url === '') {
                    continue;
                }

                // The collecting pass only gathers URLs, and the pass after it does the counting.
                if (!$collect) {
                    $totals['checked']++;
                }

                // Signed data is what a control panel save stores, so it's left alone.
                if ($link->hasTrustedEmbedData()) {
                    if (!$collect) {
                        $totals['signed']++;
                    }

                    continue;
                }

                if ($collect) {
                    $pending[$this->_fetchKey($link, $url)] = [$link, $url];

                    continue;
                }

                $label = sprintf('element %d, site %d: %s', $ref->elementId, $ref->siteId, $url);

                if (!$write) {
                    // A dry run makes no requests, but the domain check needs none.
                    if (!$link->isEmbedUrlAllowed($url)) {
                        $totals['failed']++;
                        $this->stderr("Couldn’t refresh {$label}: " . Craft::t('hyper', 'URL domain not allowed.') . "\n", Console::FG_RED);

                        continue;
                    }

                    $totals['refreshed']++;
                    $this->stdout("Would refresh {$label}\n");

                    continue;
                }

                $data = $this->_fetchEmbedData($link, $url);

                if (isset($data['error'])) {
                    $totals['failed']++;
                    $this->stderr("Couldn’t refresh {$label}: {$data['error']}\n", Console::FG_RED);

                    continue;
                }

                // Store the fetched metadata whole, as the control panel does after a preview.
                $refreshed = clone $link;
                $refreshed->linkValue = $data;
                $links[$key] = $refreshed;
                $changed = true;
                $totals['refreshed']++;
                $this->stdout("Refreshed {$label}\n");
            }

            return $changed ? $collection->withLinks($links) : $collection;
        }, $options);
    }

    /**
     * Fetches once per URL and domain policy, with the same checks as the control panel preview.
     * The link carries its link type's settings, including disabled types, whose saved links still render.
     */
    private function _fetchEmbedData(Embed $link, string $url): array
    {
        $key = $this->_fetchKey($link, $url);

        if (isset($this->_fetchedEmbedData[$key])) {
            return $this->_fetchedEmbedData[$key];
        }

        if (!$link->isEmbedUrlAllowed($url)) {
            return $this->_fetchedEmbedData[$key] = ['error' => Craft::t('hyper', 'URL domain not allowed.')];
        }

        $data = Embed::fetchEmbedData($url, $link->getEffectiveAllowedDomains());

        if (!isset($data['error'])) {
            $finalUrl = is_string($data['url'] ?? null) ? trim($data['url']) : '';

            // Metadata without a URL would drop the link's destination, and a redirect may leave the allowlist.
            if ($finalUrl === '') {
                $data = ['error' => 'The fetched metadata has no URL.'];
            } elseif (!$link->isEmbedUrlAllowed($finalUrl)) {
                $data = ['error' => Craft::t('hyper', 'URL domain not allowed.')];
            }
        }

        return $this->_fetchedEmbedData[$key] = $data;
    }

    private function _fetchKey(Embed $link, string $url): string
    {
        return Json::encode([$link->getEffectiveAllowedDomains(), $url]);
    }

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
