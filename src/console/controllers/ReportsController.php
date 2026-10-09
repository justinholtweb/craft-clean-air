<?php

namespace justinholtweb\cleanair\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\Report;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\services\Reports;
use yii\console\ExitCode;

/**
 * `php craft cleanair/reports/send` — scheduled reports, for cron.
 *
 * Run it as often as you like; each report sends once per period, and only once its day and
 * hour have arrived:
 *
 *     0,15,30,45 * * * * php /path/to/craft cleanair/reports/send
 *
 * Exits 0 for every outcome that is not a fault — not due yet, already sent, nothing to send —
 * so cron stays quiet. Exits 78 when a report is switched on but has nobody it may go to, or its
 * owner can no longer run it, and 1 when a send failed (the next run will try again).
 *
 * Pro only.
 */
class ReportsController extends Controller
{
    public $defaultAction = 'send';

    /** Send now, whatever the schedule says and even if this period's report has gone. Needs --report. */
    public bool $force = false;

    /** Only this report, named by its saved filter's handle. */
    public ?string $report = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'send') {
            $options[] = 'force';
            $options[] = 'report';
        }

        return $options;
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Edition::allowsScheduledReports(Plugin::getInstance()->isPro())) {
            $this->stderr("Scheduled reports are part of Clean Air Pro.\n", Console::FG_RED);
            return false;
        }

        return true;
    }

    public function actionSend(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->force && $this->report === null) {
            $this->stderr("--force sends regardless of the schedule, so it needs --report=<handle>.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if ($this->report !== null) {
            $report = $this->reportFor($this->report);

            if ($report === null) {
                return ExitCode::DATAERR;
            }

            $results = [(int)$report->id => $plugin->reports->run($report, null, $this->force)];
        } else {
            $results = $plugin->reports->runAll();
        }

        if ($results === []) {
            $this->stdout("No scheduled reports are switched on.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $code = ExitCode::OK;

        foreach ($results as $id => $result) {
            $report = $plugin->reports->getById($id);
            $name = $report ? ($plugin->reports->savedFilter($report)->handle ?? "#$id") : "#$id";

            [$message, $colour, $resultCode] = match ($result) {
                Reports::RESULT_SENT => ['Sent.', Console::FG_GREEN, ExitCode::OK],
                Reports::RESULT_EMPTY => ['Matched nothing, so none was sent.', Console::FG_GREY, ExitCode::OK],
                Reports::RESULT_NOTHING_NEW => ['Nothing new since the last report, so none was sent.', Console::FG_GREY, ExitCode::OK],
                Reports::RESULT_NOT_DUE => ['Not due yet.', Console::FG_GREY, ExitCode::OK],
                Reports::RESULT_ALREADY_SENT => ['This period’s report has already gone.', Console::FG_GREY, ExitCode::OK],
                Reports::RESULT_DISABLED => ['Switched off.', Console::FG_GREY, ExitCode::OK],
                Reports::RESULT_CANCELLED => ['An event handler cancelled it.', Console::FG_YELLOW, ExitCode::OK],
                Reports::RESULT_NO_RECIPIENTS => ['Nobody on its list may receive it. Check the schedule in Clean Air.', Console::FG_RED, ExitCode::CONFIG],
                Reports::RESULT_REFUSED => ['Its owner can no longer run this filter; see storage/logs/cleanair.log. Someone with access has to save the schedule again.', Console::FG_RED, ExitCode::CONFIG],
                default => ['Could not be sent; see storage/logs/cleanair.log. The next run will try again.', Console::FG_RED, ExitCode::UNSPECIFIED_ERROR],
            };

            $this->stdout(str_pad($name, 32), Console::FG_YELLOW);
            $this->stdout($message . "\n", $colour);

            // The worst outcome decides: a failure outranks a configuration problem.
            if ($resultCode === ExitCode::UNSPECIFIED_ERROR || ($resultCode === ExitCode::CONFIG && $code === ExitCode::OK)) {
                $code = $resultCode;
            }
        }

        return $code;
    }

    /** Every schedule: when it runs, when it last ran, when it's next due. */
    public function actionStatus(): int
    {
        $reports = Plugin::getInstance()->reports;
        $all = $reports->all();
        $format = fn(?\DateTimeInterface $date) => $date?->format('Y-m-d H:i T') ?? '—';

        if ($all === []) {
            $this->stdout("No scheduled reports.\n");
            return ExitCode::OK;
        }

        foreach ($all as $report) {
            $saved = $reports->savedFilter($report);

            $this->stdout(($saved->handle ?? "#{$report->id}") . "\n", Console::FG_YELLOW);
            $this->stdout('  ' . str_pad('Schedule', 14) . $report->describe() . ($report->enabled ? '' : ' (off)') . "\n");
            $this->stdout('  ' . str_pad('Runs as', 14) . ($reports->owner($report)->username ?? '—') . "\n");
            $this->stdout('  ' . str_pad('Recipients', 14) . count($report->recipientIds) . "\n");
            $this->stdout('  ' . str_pad('Last period', 14) . ($report->period ?? '—') . "\n");
            $this->stdout('  ' . str_pad('Last run', 14) . $format($report->lastRunAt) . ' ' . ($report->lastResult ?? '') . "\n");
            $this->stdout('  ' . str_pad('Last sent', 14) . $format($report->lastSentAt) . ($report->lastSentAt ? " ({$report->lastCount} results, {$report->lastNewCount} new)" : '') . "\n");
            $this->stdout('  ' . str_pad('Next due', 14) . $format($reports->nextDueAt($report)) . "\n");
        }

        return ExitCode::OK;
    }

    private function reportFor(string $handle): ?Report
    {
        $plugin = Plugin::getInstance();
        $saved = $plugin->savedFilters->getByHandle($handle);
        $report = $saved ? $plugin->reports->getByFilterId((int)$saved->id) : null;

        if ($report === null) {
            $this->stderr("No scheduled report for a saved filter with the handle “{$handle}”.\n", Console::FG_RED);
        }

        return $report;
    }
}
