<?php

namespace justinholtweb\cleanair\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\cleanair\Plugin;
use yii\console\ExitCode;

/**
 * Importing CP Filters' saved filters from the command line.
 *
 * Available in every edition — the migration path shouldn't be behind a paywall.
 */
class ImportController extends Controller
{
    /** @var bool Include filters that were deleted in CP Filters. */
    public bool $includeDeleted = false;

    /** @var bool Import every filter as a shared one rather than private to its author. */
    public bool $share = false;

    /** @var bool Skip filters whose name and owner already exist in Clean Air. */
    public bool $skipExisting = true;

    /** @var bool Also carry over CP Filters' own settings. */
    public bool $importSettings = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'includeDeleted',
            'share',
            'skipExisting',
            'importSettings',
        ]);
    }

    /**
     * Shows what an import would do, without writing anything.
     */
    public function actionIndex(): int
    {
        $importer = Plugin::getInstance()->importer;

        if (!$importer->isAvailable()) {
            $this->stdout("No CP Filters table found — nothing to import.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $rows = $importer->preview($this->includeDeleted);

        if (!$rows) {
            $this->stdout("CP Filters is installed, but it has no saved filters.\n");
            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $marker = $row['importable'] ? '✓' : '✗';
            $colour = $row['importable'] ? ($row['warnings'] ? Console::FG_YELLOW : Console::FG_GREEN) : Console::FG_RED;

            $this->stdout("$marker ", $colour);
            $this->stdout(str_pad($row['name'], 40));
            $this->stdout(str_pad($row['typeKey'], 12), Console::FG_GREY);
            $this->stdout(count($row['criteria']) . " condition(s)\n", Console::FG_GREY);

            foreach ($row['warnings'] as $warning) {
                $this->stdout("    ! $warning\n", Console::FG_YELLOW);
            }
        }

        $this->stdout("\nRun `craft cleanair/import/run` to import these.\n");

        return ExitCode::OK;
    }

    /**
     * Imports the filters.
     */
    public function actionRun(): int
    {
        $importer = Plugin::getInstance()->importer;

        if (!$importer->isAvailable()) {
            $this->stderr("No CP Filters table found — nothing to import.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $report = $importer->import([
            'includeDeleted' => $this->includeDeleted,
            'share' => $this->share,
            'skipExisting' => $this->skipExisting,
            'importSettings' => $this->importSettings,
        ]);

        foreach ($report['messages'] as $message) {
            $colour = match ($message['status']) {
                'imported' => Console::FG_GREEN,
                'warning' => Console::FG_YELLOW,
                'failed' => Console::FG_RED,
                default => Console::FG_GREY,
            };

            $this->stdout(str_pad($message['status'], 10), $colour);
            $this->stdout($message['name'] . "\n");

            foreach ($message['notes'] as $note) {
                $this->stdout("    $note\n", Console::FG_GREY);
            }
        }

        $this->stdout(sprintf(
            "\n%d imported, %d skipped, %d failed.\n",
            $report['imported'],
            $report['skipped'],
            $report['failed'],
        ), Console::FG_GREEN);

        return $report['failed'] > 0 ? ExitCode::DATAERR : ExitCode::OK;
    }
}
