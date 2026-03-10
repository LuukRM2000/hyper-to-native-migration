<?php

namespace lm2k\hypertolink\models;

use yii\base\Model;

class MigrationReport extends Model
{
    public string $runId;
    public string $action;
    public bool $dryRun = false;
    public bool $verbose = false;
    public string $reportPath;
    public string $jsonPath;
    public array $summary = [];
    public array $lines = [];
}
