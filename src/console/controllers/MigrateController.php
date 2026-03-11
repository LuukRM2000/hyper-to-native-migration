<?php

namespace lm2k\hypertolink\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use yii\console\ExitCode;

class MigrateController extends Controller
{
    public ?string $field = null;
    public bool $dryRun = false;
    public bool $force = false;
    public bool $verbose = false;
    public bool $createBackup = false;
    public int $batchSize = 100;
    public bool $applyProjectConfig = true;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'field';
        $options[] = 'dryRun';
        $options[] = 'force';
        $options[] = 'verbose';
        $options[] = 'createBackup';
        $options[] = 'batchSize';
        $options[] = 'applyProjectConfig';

        return $options;
    }

    public function optionAliases(): array
    {
        return [
            'f' => 'field',
            'n' => 'dryRun',
            'b' => 'createBackup',
            'v' => 'verbose',
            'p' => 'applyProjectConfig',
        ];
    }

    public function actionAudit(): int
    {
        [, $exitCode] = $this->runAuditStage();
        return $exitCode;
    }

    public function actionFields(): int
    {
        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to mutate field config without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [, $exitCode] = $this->runFieldStage();
        return $exitCode;
    }

    public function actionContent(): int
    {
        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to write content without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [, $exitCode] = $this->runContentStage();
        return $exitCode;
    }

    public function actionAll(): int
    {
        $this->stdout("Running full Hyper to Link migration workflow.\n\n", Console::FG_YELLOW);

        [$audit, $auditExitCode] = $this->runAuditStage();
        if ($auditExitCode !== ExitCode::OK) {
            $this->stderr("Audit found blocking issues. Resolve them before running the full workflow.\n", Console::FG_RED);
            return $auditExitCode;
        }

        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to run the full workflow without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [, $fieldExitCode] = $this->runFieldStage($audit);
        if ($fieldExitCode !== ExitCode::OK) {
            return $fieldExitCode;
        }

        if ($this->dryRun) {
            $this->stdout("Skipping project config apply during dry run.\n", Console::FG_YELLOW);
        } elseif ($this->applyProjectConfig) {
            $projectConfigExitCode = $this->applyProjectConfigChanges();
            if ($projectConfigExitCode !== ExitCode::OK) {
                return $projectConfigExitCode;
            }
        } else {
            $this->stdout("Skipping project config apply because --apply-project-config=0 was provided.\n", Console::FG_YELLOW);
        }

        [, $contentExitCode] = $this->runContentStage($audit);
        if ($contentExitCode !== ExitCode::OK) {
            return $contentExitCode;
        }

        $this->stdout("\nFull Hyper to Link workflow completed successfully.\n", Console::FG_GREEN);
        return ExitCode::OK;
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

    public function actionMismatches(): int
    {
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('mismatches', true, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);

        $payload = [
            'summary' => [
                'mismatches' => count($audit->mismatchReferences),
            ],
            'mismatches' => $audit->mismatchReferences,
        ];

        file_put_contents($report->jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        file_put_contents($report->reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $this->stdout(sprintf("Potential Hyper API mismatches: %d\n", count($audit->mismatchReferences)), Console::FG_YELLOW);

        foreach ($audit->mismatchReferences as $mismatch) {
            $this->stdout(sprintf(
                "- %s:%d | %s -> %s\n  %s\n  %s\n",
                $mismatch['file'],
                $mismatch['line'],
                $mismatch['pattern'],
                $mismatch['replacement'],
                $mismatch['reason'],
                $mismatch['snippet']
            ));
        }

        $this->stdout("\nMismatch report written to {$report->reportPath}\n", Console::FG_GREEN);

        return count($audit->mismatchReferences) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function runAuditStage(): array
    {
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('audit', $this->dryRun, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);
        $plugin->getReport()->writeAudit($report, $audit, $this);
        $this->stdout("Audit written to {$report->reportPath}\n", Console::FG_GREEN);

        return [$audit, $audit->hasBlockingIssues() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK];
    }

    private function runFieldStage(?AuditResult $audit = null): array
    {
        $plugin = HyperToLink::$plugin;
        $audit ??= $plugin->getAudit()->buildAudit($this->field);
        $report = $plugin->getReport()->beginRun('fields', $this->dryRun, $this->verbose);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'field/config migration');

        $result = $plugin->getFieldMigration()->migrate($audit, [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'verbose' => $this->verbose,
        ]);

        $plugin->getReport()->writeFieldResult($report, $result, $this);
        $this->stdout("Field migration report written to {$report->reportPath}\n", Console::FG_GREEN);

        return [$result, $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK];
    }

    private function runContentStage(?AuditResult $audit = null): array
    {
        $plugin = HyperToLink::$plugin;
        $audit ??= $plugin->getAudit()->buildAudit($this->field);
        $report = $plugin->getReport()->beginRun('content', $this->dryRun, $this->verbose);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'content migration');

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

        return [$result, $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK];
    }

    private function applyProjectConfigChanges(): int
    {
        $craft = Craft::getAlias('@root/craft');
        if (!is_file($craft)) {
            $this->stderr("Could not find the Craft CLI executable at @root/craft.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $command = sprintf(
            '%s %s project-config/apply',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($craft)
        );

        $this->stdout("Applying project config changes...\n", Console::FG_YELLOW);
        passthru($command, $status);

        if ($status !== ExitCode::OK) {
            $this->stderr("Project config apply failed.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
