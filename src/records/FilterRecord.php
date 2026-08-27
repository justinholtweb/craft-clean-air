<?php

namespace justinholtweb\cleanair\records;

use craft\db\ActiveRecord;
use justinholtweb\cleanair\migrations\Install;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $elementType
 * @property string|null $source
 * @property string|null $criteria
 * @property int|null $userId
 * @property string $visibility
 * @property bool $pinned
 * @property int $sortOrder
 * @property string|null $dateLastRun
 */
class FilterRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_FILTERS;
    }
}
