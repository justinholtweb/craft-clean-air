<?php

namespace justinholtweb\cleanair\models;

use craft\base\Model;
use Psr\Log\LogLevel;

/**
 * Clean Air plugin settings.
 *
 * Everything here can also live in `config/cleanair.php`. CP Filters was config-file only,
 * which meant a content editor could never widen their own view without a deploy; these are
 * editable in the control panel too, and the config file still wins where both are set.
 */
class Settings extends Model
{
    /**
     * @var array<string,string[]> Source keys each element type is allowed to filter, keyed
     *        by element type key (`entries`, `assets`, …). An empty array for a type means
     *        every source the user already has permission to see. This is the replacement for
     *        CP Filters' `filterableEntryTypeIds` and friends, and the importer folds those
     *        settings into it.
     */
    public array $filterableSources = [];

    /**
     * @var string[] Element type classes Clean Air will not offer at all.
     */
    public array $disabledElementTypes = [];

    /**
     * @var bool|null Whether Craft Commerce elements are offered. Null auto-detects, which is
     *        what you want; CP Filters made you say so explicitly.
     */
    public ?bool $includeCommerce = null;

    /**
     * @var array<string,string|string[]> Extra field classes to make filterable, as
     *        `'my\field\Class' => 'text'` (a Clean Air kind) or, in CP Filters' style, an
     *        explicit list of operator labels. Both forms are accepted.
     */
    public array $additionalFieldTypes = [];

    /**
     * @var int Results per page in the control panel table.
     */
    public int $resultsPerPage = 50;

    /**
     * @var int Hard cap on rows a single export will write. 0 means no cap.
     */
    public int $maxExportRows = 0;

    /**
     * @var int Elements loaded at a time when exporting. Keeps a 200,000-row export inside a
     *          sane memory ceiling.
     */
    public int $exportBatchSize = 100;

    /**
     * @var int Result counts above this are exported on the queue instead of in the request.
     *          0 always exports inline. Pro only — Lite always exports inline.
     */
    public int $queueExportThreshold = 2000;

    /**
     * @var int Days a finished export file is kept before it is pruned. 0 keeps them forever,
     *          which is rarely what anyone means.
     */
    public int $exportRetentionDays = 7;

    /**
     * @var int Ceiling on the ID set collected for a `match any` filter. An OR filter is run
     *          one criterion at a time and unioned, so an unbounded one on a large site is a
     *          way to run out of memory. Results are truncated and the UI says so.
     */
    public int $anyMatchIdLimit = 50000;

    /**
     * @var string Delimiter used for CSV exports. Some locales expect a semicolon, and Excel
     *             is famously opinionated about it.
     */
    public string $csvDelimiter = ',';

    /**
     * @var bool Whether to write a UTF-8 byte order mark at the top of CSV exports. Excel
     *           mangles accented characters without one.
     */
    public bool $csvBom = true;

    /**
     * @var array<string,string[]> Default result columns per element type key. Empty falls
     *        back to the element type's own defaults.
     */
    public array $defaultColumns = [];

    /**
     * @var bool Whether sources with no elements in them are still listed.
     */
    public bool $showEmptySources = true;

    /**
     * @var bool Whether a filter's criteria are shown in the URL, making a result set
     *           shareable by pasting the address. Off if you would rather filters weren't
     *           reconstructable from browser history.
     */
    public bool $shareableUrls = true;

    /**
     * @var string Log level for Clean Air's own log target.
     */
    public string $logLevel = LogLevel::WARNING;

    public function rules(): array
    {
        return [
            [['resultsPerPage', 'exportBatchSize'], 'integer', 'min' => 1],
            [['maxExportRows', 'queueExportThreshold', 'exportRetentionDays', 'anyMatchIdLimit'], 'integer', 'min' => 0],
            [['csvDelimiter'], 'string', 'min' => 1, 'max' => 1],
        ];
    }

    /**
     * The allowed source keys for an element type key, or an empty array meaning "no
     * restriction beyond the user's own permissions".
     *
     * @return string[]
     */
    public function allowedSources(string $typeKey): array
    {
        return array_values(array_filter((array)($this->filterableSources[$typeKey] ?? [])));
    }
}
