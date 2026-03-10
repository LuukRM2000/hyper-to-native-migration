<?php

namespace lm2k\hypertolink\models;

use yii\base\Model;

class AuditResult extends Model
{
    /** @var FieldAudit[] */
    public array $fields = [];
    public array $codeReferences = [];
    public array $notes = [];

    public function hasBlockingIssues(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                return true;
            }
        }

        return false;
    }
}
