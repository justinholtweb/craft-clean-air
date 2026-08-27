<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\queue\QueueInterface;
use DateTime;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\queue\ExportJob;
use justinholtweb\cleanair\records\ExportRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Writing results to a file.
 *
 * Everything is streamed a batch at a time and written straight to disk, so exporting a
 * quarter of a million rows costs the same memory as exporting fifty. A big export can go to
 * the queue and be collected afterwards — the difference between a report you can run and
 * one that times out at thirty seconds.
 */
class Exports extends Component
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_TSV = 'tsv';
    public const FORMAT_JSON = 'json';
    public const FORMAT_XML = 'xml';

    /** @return array<string,string> */
    public function formats(): array
    {
        return [
            self::FORMAT_CSV => 'CSV',
            self::FORMAT_TSV => 'TSV',
            self::FORMAT_JSON => 'JSON',
            self::FORMAT_XML => 'XML',
        ];
    }

    /** @return array<string,string> Formats this edition may produce. */
    public function availableFormats(): array
    {
        $isPro = Plugin::getInstance()->isPro();

        return array_filter(
            $this->formats(),
            fn(string $format) => Edition::allowsFormat($isPro, $format),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether a result set of this size should be handed to the queue rather than written
     * inside the request.
     */
    public function shouldQueue(int $rowCount): bool
    {
        if (!Edition::allowsQueuedExports(Plugin::getInstance()->isPro())) {
            return false;
        }

        $threshold = Plugin::getInstance()->getSettings()->queueExportThreshold;

        return $threshold > 0 && $rowCount > $threshold;
    }

    /**
     * Writes an export now and returns the record describing it.
     */
    public function run(FilterSet $filter, string $format, ?string $label = null, ?int $userId = null, ?int $filterId = null): ExportRecord
    {
        $record = $this->createRecord($filter, $format, $label, $userId, $filterId);
        $this->fulfil($record, $filter);
        return $record;
    }

    /**
     * Queues an export. The record is created straight away so the user has something to
     * watch, and the job fills it in.
     */
    public function queue(FilterSet $filter, string $format, ?string $label = null, ?int $userId = null, ?int $filterId = null): ExportRecord
    {
        $record = $this->createRecord($filter, $format, $label, $userId, $filterId);

        /** @var QueueInterface $queue */
        $queue = Craft::$app->getQueue();
        $queue->push(new ExportJob([
            'exportId' => $record->id,
            'filterConfig' => $filter->toArray(),
        ]));

        return $record;
    }

    public function createRecord(FilterSet $filter, string $format, ?string $label, ?int $userId, ?int $filterId): ExportRecord
    {
        if (!isset($this->formats()[$format])) {
            throw new InvalidArgumentException("Unknown export format “$format”.");
        }

        $record = new ExportRecord();
        $record->filterId = $filterId;
        $record->userId = $userId;
        $record->elementType = $filter->elementType;
        $record->format = $format;
        $record->status = ExportRecord::STATUS_PENDING;
        $record->label = $label;
        $record->save(false);

        return $record;
    }

    /**
     * Runs the query and writes the file. Used by both the inline path and the queue job, so
     * a queued export and an immediate one produce byte-identical files.
     */
    public function fulfil(ExportRecord $record, FilterSet $filter, ?callable $onProgress = null): void
    {
        $record->status = ExportRecord::STATUS_RUNNING;
        $record->save(false);

        try {
            $type = Plugin::getInstance()->filters->type($filter);
            $columns = Plugin::getInstance()->columns->resolve($filter, $type);
            $path = $this->pathFor($record);

            FileHelper::createDirectory(dirname($path));

            $handle = fopen($path, 'wb');

            if ($handle === false) {
                throw new \RuntimeException("Couldn’t open $path for writing.");
            }

            try {
                $rowCount = $this->write($handle, $filter, $type, $columns, $record->format, $onProgress);
            } finally {
                fclose($handle);
            }

            $record->status = ExportRecord::STATUS_DONE;
            $record->rowCount = $rowCount;
            $record->path = $path;
            $record->filename = $this->filenameFor($record);
            $record->filesize = @filesize($path) ?: null;
            $record->dateFinished = Db::prepareDateForDb(new DateTime());
            $record->save(false);
        } catch (Throwable $e) {
            $record->status = ExportRecord::STATUS_FAILED;
            $record->error = $e->getMessage();
            $record->save(false);
            Craft::error('Clean Air export failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            throw $e;
        }
    }

    /**
     * @param resource $handle
     * @param string[] $columns
     */
    private function write($handle, FilterSet $filter, $type, array $columns, string $format, ?callable $onProgress): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $columnsService = Plugin::getInstance()->columns;
        $max = $settings->maxExportRows > 0 ? $settings->maxExportRows : null;

        $headers = array_map(
            fn(string $key) => $columnsService->label($type, $filter->source, $key),
            $columns,
        );

        $delimiter = $format === self::FORMAT_TSV ? "\t" : $settings->csvDelimiter;
        $rowCount = 0;

        if ($format === self::FORMAT_CSV || $format === self::FORMAT_TSV) {
            // Excel reads a UTF-8 file as Latin-1 unless it finds a byte order mark, which is
            // how exported names come back full of question marks.
            if ($format === self::FORMAT_CSV && $settings->csvBom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }
            fputcsv($handle, $headers, $delimiter, '"', '\\');
        } elseif ($format === self::FORMAT_JSON) {
            fwrite($handle, "[\n");
        } elseif ($format === self::FORMAT_XML) {
            fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<results>\n");
        }

        $keys = array_map(fn(string $key) => $this->xmlKey($key), $columns);

        foreach (Plugin::getInstance()->filters->each($filter, null, $max) as $element) {
            $row = [];
            foreach ($columns as $key) {
                $row[] = $columnsService->value($element, $type, $filter->source, $key);
            }

            if ($format === self::FORMAT_CSV || $format === self::FORMAT_TSV) {
                fputcsv($handle, $row, $delimiter, '"', '\\');
            } elseif ($format === self::FORMAT_JSON) {
                $assoc = array_combine($columns, $row);
                fwrite($handle, ($rowCount > 0 ? ",\n" : '') . '  ' . json_encode($assoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } elseif ($format === self::FORMAT_XML) {
                fwrite($handle, "  <result>\n");
                foreach ($row as $index => $value) {
                    $key = $keys[$index];
                    fwrite($handle, "    <$key>" . Html::encode($value) . "</$key>\n");
                }
                fwrite($handle, "  </result>\n");
            }

            $rowCount++;

            if ($onProgress !== null && $rowCount % 100 === 0) {
                $onProgress($rowCount);
            }
        }

        if ($format === self::FORMAT_JSON) {
            fwrite($handle, "\n]\n");
        } elseif ($format === self::FORMAT_XML) {
            fwrite($handle, "</results>\n");
        }

        return $rowCount;
    }

    /** Column labels become XML element names, which have rules that "Post Date" breaks. */
    private function xmlKey(string $key): string
    {
        $key = preg_replace('/[^A-Za-z0-9_\-]/', '-', $key) ?? 'field';
        return preg_match('/^[A-Za-z_]/', $key) === 1 ? $key : "f-$key";
    }

    public function pathFor(ExportRecord $record): string
    {
        $directory = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'cleanair-exports';
        return $directory . DIRECTORY_SEPARATOR . $record->uid . '.' . $record->format;
    }

    public function filenameFor(ExportRecord $record): string
    {
        $base = $record->label ? StringHelper::toKebabCase($record->label) : 'cleanair-export';
        $base = $base ?: 'cleanair-export';
        $created = $record->dateCreated ? DateTimeHelper::toDateTime($record->dateCreated) : false;
        $date = ($created ?: new DateTime())->format('Y-m-d-Hi');

        return "$base-$date.$record->format";
    }

    public function getById(int $id): ?ExportRecord
    {
        return ExportRecord::findOne($id);
    }

    /**
     * @return ExportRecord[]
     */
    public function forUser(?int $userId, int $limit = 25): array
    {
        return ExportRecord::find()
            ->where(['userId' => $userId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function delete(ExportRecord $record): void
    {
        if ($record->path && is_file($record->path)) {
            @unlink($record->path);
        }
        $record->delete();
    }

    /**
     * Deletes export files older than the retention setting. Exports are result sets, often
     * of personal data, and there is no reason for them to sit in `storage/runtime` forever.
     */
    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->exportRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");
        $pruned = 0;

        $records = ExportRecord::find()
            ->where(['<', 'dateCreated', $cutoff->format('Y-m-d H:i:s')])
            ->all();

        foreach ($records as $record) {
            /** @var ExportRecord $record */
            $this->delete($record);
            $pruned++;
        }

        return $pruned;
    }
}
