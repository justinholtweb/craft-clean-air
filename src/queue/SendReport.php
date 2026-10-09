<?php

namespace justinholtweb\cleanair\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\cleanair\Plugin;

/**
 * Sends one scheduled report if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see \justinholtweb\cleanair\services\Reports::run()} checks the
 * schedule and claims the period, so a job that runs late, twice, or after cron already sent is
 * a no-op.
 */
class SendReport extends BaseJob
{
    public ?int $reportId = null;

    public function execute($queue): void
    {
        if (!$this->reportId) {
            return;
        }

        $reports = Plugin::getInstance()->reports;
        $report = $reports->getById($this->reportId);

        if ($report) {
            $reports->run($report);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('cleanair', 'Sending a Clean Air report');
    }

    public function getTtr(): int
    {
        // A full export of the filter, then one email per recipient.
        return 1800;
    }
}
