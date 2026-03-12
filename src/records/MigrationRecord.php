<?php

namespace lm2k\hypertolink\records;

use craft\db\ActiveRecord;

class MigrationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%linkmigrator_migrations}}';
    }
}
