<?php

namespace justinholtweb\cleanair\migrations;

use craft\db\Migration;
use justinholtweb\cleanair\services\Reports;

/**
 * Adds `cleanair_reports`: one row per scheduled report, holding its schedule and its marker.
 */
class m261009_000000_reports extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Install::TABLE_REPORTS)) {
            Reports::createTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Install::TABLE_REPORTS);

        return true;
    }
}
