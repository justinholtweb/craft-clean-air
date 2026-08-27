<?php

namespace justinholtweb\cleanair\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Two tables: the filters people keep, and the exports they asked the queue to build.
 *
 * Nothing here touches content. Uninstalling Clean Air takes its own filters with it and
 * leaves the site exactly as it found it.
 */
class Install extends Migration
{
    public const TABLE_FILTERS = '{{%cleanair_filters}}';
    public const TABLE_EXPORTS = '{{%cleanair_exports}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_FILTERS)) {
            $this->createTable(self::TABLE_FILTERS, [
                'id' => $this->primaryKey(),
                'name' => $this->string(255)->notNull(),
                'handle' => $this->string(64)->notNull(),
                'elementType' => $this->string(255)->notNull(),
                'source' => $this->string(255),
                // The serialised FilterSet: criteria, match mode, columns, sort, flags.
                'criteria' => $this->mediumText(),
                'userId' => $this->integer(),
                'visibility' => $this->string(16)->notNull()->defaultValue('private'),
                'pinned' => $this->boolean()->notNull()->defaultValue(false),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateLastRun' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_FILTERS, ['handle'], true);
            $this->createIndex(null, self::TABLE_FILTERS, ['userId']);
            $this->createIndex(null, self::TABLE_FILTERS, ['elementType']);
            $this->createIndex(null, self::TABLE_FILTERS, ['visibility']);

            $this->addForeignKey(null, self::TABLE_FILTERS, ['userId'], Table::USERS, ['id'], 'SET NULL', null);
        }

        if (!$this->db->tableExists(self::TABLE_EXPORTS)) {
            $this->createTable(self::TABLE_EXPORTS, [
                'id' => $this->primaryKey(),
                'filterId' => $this->integer(),
                'userId' => $this->integer(),
                'elementType' => $this->string(255)->notNull(),
                'format' => $this->string(16)->notNull()->defaultValue('csv'),
                'status' => $this->string(16)->notNull()->defaultValue('pending'),
                'label' => $this->string(255),
                'rowCount' => $this->integer()->notNull()->defaultValue(0),
                'filename' => $this->string(255),
                'path' => $this->string(500),
                'filesize' => $this->bigInteger(),
                'error' => $this->text(),
                'dateFinished' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_EXPORTS, ['userId']);
            $this->createIndex(null, self::TABLE_EXPORTS, ['status']);
            $this->createIndex(null, self::TABLE_EXPORTS, ['dateCreated']);

            $this->addForeignKey(null, self::TABLE_EXPORTS, ['userId'], Table::USERS, ['id'], 'SET NULL', null);
            $this->addForeignKey(null, self::TABLE_EXPORTS, ['filterId'], self::TABLE_FILTERS, ['id'], 'SET NULL', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_EXPORTS);
        $this->dropTableIfExists(self::TABLE_FILTERS);
        return true;
    }
}
