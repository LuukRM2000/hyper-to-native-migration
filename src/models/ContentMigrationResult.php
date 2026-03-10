<?php

namespace lm2k\hypertolink\models;

use yii\base\Model;

class ContentMigrationResult extends Model
{
    public array $migrated = [];
    public array $skipped = [];
    public array $warnings = [];
    public array $errors = [];
    public array $backups = [];

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
