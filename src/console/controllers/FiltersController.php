<?php

namespace justinholtweb\cleanair\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\Plugin;
use yii\console\ExitCode;

/**
 * Saved filters from the command line.
 *
 * This is the piece that turns a saved filter into a scheduled report: a cron entry running
 * `cleanair/filters/export stale-drafts --path=...` every Monday morning is a thing CP
 * Filters could never do, because everything it knew lived behind a control panel session.
 *
 * Pro only.
 */
class FiltersController extends Controller
{
    /** @var string Export format: csv, tsv, json or xml. */
    public string $format = 'csv';

    /** @var string|null Where to write the export. Defaults to Craft's storage directory. */
    public ?string $path = null;

    /** @var int How many rows `run` prints. */
    public int $limit = 20;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'export' => array_merge($options, ['format', 'path']),
            'run' => array_merge($options, ['limit']),
            default => $options,
        };
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Edition::allowsProgrammaticAccess(Plugin::getInstance()->isPro())) {
            $this->stderr("Clean Air Lite doesn’t include the console commands. Upgrade to Pro.\n", Console::FG_RED);
            return false;
        }

        return true;
    }

    /**
     * Lists the saved filters on this site.
     */
    public function actionIndex(): int
    {
        $filters = Plugin::getInstance()->savedFilters->all(null);

        if (!$filters) {
            $this->stdout("No saved filters.\n");
            return ExitCode::OK;
        }

        foreach ($filters as $filter) {
            $this->stdout(str_pad($filter->handle, 32), Console::FG_YELLOW);
            $this->stdout(str_pad($filter->name, 40));
            $this->stdout($filter->elementType . "\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Runs a saved filter and prints what it matched.
     *
     * @param string $handle The saved filter's handle.
     */
    public function actionRun(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $saved = $plugin->savedFilters->getByHandle($handle);

        if (!$saved) {
            $this->stderr("No saved filter with the handle “$handle”.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $warnings = [];
        $count = $plugin->filters->count($saved->filter, $warnings);

        foreach ($warnings as $warning) {
            $this->stdout("! $warning\n", Console::FG_YELLOW);
        }

        $this->stdout("$count " . ($count === 1 ? 'result' : 'results') . "\n\n", Console::FG_GREEN);

        $shown = 0;
        foreach ($plugin->filters->each($saved->filter, null, $this->limit) as $element) {
            $this->stdout(str_pad('#' . $element->id, 10), Console::FG_GREY);
            $this->stdout((string)$element->getUiLabel() . "\n");
            $shown++;
        }

        if ($count > $shown) {
            $this->stdout("\n… and " . ($count - $shown) . " more.\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Exports a saved filter to a file.
     *
     * @param string $handle The saved filter's handle.
     */
    public function actionExport(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $saved = $plugin->savedFilters->getByHandle($handle);

        if (!$saved) {
            $this->stderr("No saved filter with the handle “$handle”.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!isset($plugin->exports->formats()[$this->format])) {
            $this->stderr("Unknown format “$this->format”. Use csv, tsv, json or xml.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $record = $plugin->exports->run($saved->filter, $this->format, $saved->name, null, $saved->id);

        $destination = $this->path
            ? FileHelper::normalizePath($this->path)
            : Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $record->filename;

        if (is_dir($destination)) {
            $destination .= DIRECTORY_SEPARATOR . $record->filename;
        }

        FileHelper::createDirectory(dirname($destination));

        if (!@copy($record->path, $destination)) {
            $this->stderr("Couldn’t write to $destination.\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        $this->stdout("Wrote $record->rowCount rows to $destination\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Prints the count for a filter given as JSON, for scripting against filters that aren't
     * saved.
     *
     * @param string $json The serialised filter.
     */
    public function actionCount(string $json): int
    {
        $config = json_decode($json, true);

        if (!is_array($config)) {
            $this->stderr("That isn’t valid JSON.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $warnings = [];
        $count = Plugin::getInstance()->filters->count(FilterSet::fromArray($config), $warnings);

        foreach ($warnings as $warning) {
            $this->stderr("! $warning\n", Console::FG_YELLOW);
        }

        $this->stdout("$count\n");

        return ExitCode::OK;
    }
}
