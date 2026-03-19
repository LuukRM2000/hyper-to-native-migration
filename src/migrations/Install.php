<?php

namespace lm2k\hypertolink\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $legacyTable = '{{%hypertolink_migrations}}';
        $currentTable = '{{%linkmigrator_migrations}}';

        if ($this->db->tableExists($legacyTable) && !$this->db->tableExists($currentTable)) {
            $this->renameTable($legacyTable, $currentTable);
        }

        if (!$this->db->tableExists($currentTable)) {
            $this->createTable($currentTable, [
                'id' => $this->primaryKey(),
                'action' => $this->string(32)->notNull(),
                'fieldHandle' => $this->string()->notNull(),
                'ownerId' => $this->integer(),
                'ownerUid' => $this->uid(),
                'siteId' => $this->integer(),
                'status' => $this->string(32)->notNull(),
                'warningsJson' => $this->text(),
                'backupJson' => $this->longText(),
                'backupPath' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->migrationIndexExists($currentTable, ['action', 'fieldHandle', 'ownerId', 'siteId'])) {
            $this->createIndex(
                null,
                $currentTable,
                ['action', 'fieldHandle', 'ownerId', 'siteId'],
                true
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%linkmigrator_migrations}}');
        return true;
    }

    private function migrationIndexExists(string $table, array $columns): bool
    {
        $rawTable = $this->db->schema->getRawTableName($table);
        $indexes = $this->db->createCommand(sprintf(
            'SHOW INDEX FROM %s',
            $this->db->quoteTableName($rawTable)
        ))->queryAll();

        $uniqueIndexes = [];
        foreach ($indexes as $index) {
            if ((int)($index['Non_unique'] ?? 1) !== 0) {
                continue;
            }

            $keyName = (string)($index['Key_name'] ?? '');
            $columnName = (string)($index['Column_name'] ?? '');
            $sequence = (int)($index['Seq_in_index'] ?? 0);
            if ($keyName === '' || $columnName === '' || $sequence < 1) {
                continue;
            }

            $uniqueIndexes[$keyName][$sequence - 1] = $columnName;
        }

        foreach ($uniqueIndexes as $indexColumns) {
            ksort($indexColumns);
            if (array_values($indexColumns) === $columns) {
                return true;
            }
        }

        return false;
    }
}
