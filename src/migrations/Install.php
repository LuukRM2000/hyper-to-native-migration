<?php

namespace lm2k\hypertolink\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%linkmigrator_migrations}}', [
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

        $this->createIndex(
            null,
            '{{%linkmigrator_migrations}}',
            ['action', 'fieldHandle', 'ownerId', 'siteId'],
            true
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%linkmigrator_migrations}}');
        return true;
    }
}
