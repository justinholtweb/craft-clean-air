<?php

namespace justinholtweb\cleanair\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\cleanair\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping for export files.
 */
class ExportsController extends Controller
{
    /**
     * Deletes export files older than the retention setting. Worth a nightly cron entry:
     * exports are snapshots of real content and shouldn't accumulate.
     */
    public function actionPrune(): int
    {
        $pruned = Plugin::getInstance()->exports->prune();

        $this->stdout("Pruned $pruned export(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
