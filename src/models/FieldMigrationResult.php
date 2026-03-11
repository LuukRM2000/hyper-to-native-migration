<?php

namespace lm2k\hypertolink\models;

use yii\base\Model;

class FieldMigrationResult extends Model
{
    public array $migrated = [];
    public array $skipped = [];
    public array $warnings = [];
    public array $errors = [];

    public function hasErrors($attribute = null): bool
    {
        return $this->errors !== [];
    }
}
