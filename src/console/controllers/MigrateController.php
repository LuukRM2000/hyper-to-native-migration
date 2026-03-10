<?php

namespace lm2k\hypertolink\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use lm2k\hypertolink\HyperToLink;
use yii\console\ExitCode;

class MigrateController extends Controller
{
    public ?string $field = null;
    public bool $dryRun = false;
    public bool $force = false;
    public bool $verbose = false;
    public bool $createBackup = false;
    public int $batchSize = 100;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'field';
        $options[] = 'dryRun';
        $options[] = 'force';
        $options[] = 'verbose';
        $options[] = 'createBackup';
        $options[] = 'batchSize';

        return $options;
    }

    public function optionAliases(): array
    {
        return [
            'f' => 'field',
            'n' => 'dryRun',
            'b' => 'createBackup',
            'v' => 'verbose',
        ];
    }

    public function actionAudit(): int
    {
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('audit', $this->dryRun, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);
        $plugin->getReport()->writeAudit($report, $audit, $this);
        $this->stdout("Audit written to {$report->reportPath}\n", Console::FG_GREEN);

        return $audit->hasBlockingIssues() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    public function actionFields(): int
    {
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('fields', $this->dryRun, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'field/config migration');

        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to mutate field config without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $plugin->getFieldMigration()->migrate($audit, [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'verbose' => $this->verbose,
        ]);

        $plugin->getReport()->writeFieldResult($report, $result, $this);
        $this->stdout("Field migration report written to {$report->reportPath}\n", Console::FG_GREEN);

        return $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    public function actionContent(): int
    {
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('content', $this->dryRun, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'content migration');

        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to write content without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $plugin->getContentMigration()->migrate($audit, [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'verbose' => $this->verbose,
            'createBackup' => $this->createBackup,
            'batchSize' => max(1, $this->batchSize),
        ]);

        $plugin->getReport()->writeContentResult($report, $result, $this);
        $this->stdout("Content migration report written to {$report->reportPath}\n", Console::FG_GREEN);

        return $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    public function actionRollbackInfo(): int
    {
        $records = HyperToLink::$plugin->getState()->summaries($this->field);
        $this->stdout("Rollback information is informational only. Hyper is intentionally left installed.\n\n", Console::FG_YELLOW);

        foreach ($records as $record) {
            $this->stdout(sprintf(
                "%s | %s | migrated=%d skipped=%d warnings=%d backups=%d last=%s\n",
                $record['action'],
                $record['fieldHandle'],
                $record['migratedCount'],
                $record['skippedCount'],
                $record['warningCount'],
                $record['backupCount'],
                $record['dateUpdated'] ?? 'n/a'
            ));
        }

        return ExitCode::OK;
    }
}
