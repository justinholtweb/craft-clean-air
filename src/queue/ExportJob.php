<?php

namespace justinholtweb\cleanair\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\records\ExportRecord;

/**
 * Builds a large export away from the request.
 *
 * The filter travels with the job rather than being looked up, so the export is of the filter
 * as it was when it was asked for — editing the saved filter afterwards doesn't quietly
 * change what lands in the file.
 */
class ExportJob extends BaseJob
{
    public ?int $exportId = null;

    /** @var array The serialised FilterSet. */
    public array $filterConfig = [];

    public function execute($queue): void
    {
        if (!$this->exportId) {
            return;
        }

        $record = ExportRecord::findOne($this->exportId);

        if (!$record) {
            return;
        }

        $filter = FilterSet::fromArray($this->filterConfig);
        $total = null;

        Plugin::getInstance()->exports->fulfil($record, $filter, function(int $written) use ($queue, &$total) {
            // The count is only fetched once something has been written — on a filter that
            // matches nothing there's no reason to have paid for it at all.
            $total ??= max(1, Plugin::getInstance()->filters->count(FilterSet::fromArray($this->filterConfig)));
            $this->setProgress($queue, min(1, $written / $total), Craft::t('cleanair', '{written} rows written', ['written' => $written]));
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('cleanair', 'Building a Clean Air export');
    }
}
