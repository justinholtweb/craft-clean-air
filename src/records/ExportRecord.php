<?php

namespace justinholtweb\cleanair\records;

use craft\db\ActiveRecord;
use justinholtweb\cleanair\migrations\Install;

/**
 * @property int $id
 * @property int|null $filterId
 * @property int|null $userId
 * @property string $elementType
 * @property string $format
 * @property string $status
 * @property string|null $label
 * @property int $rowCount
 * @property string|null $filename
 * @property string|null $path
 * @property int|null $filesize
 * @property string|null $error
 * @property string|null $dateFinished
 */
class ExportRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return Install::TABLE_EXPORTS;
    }
}
