<?php

namespace lm2k\hypertolink\services;

use craft\base\Component;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\MigrationReport;
use yii\console\ExitCode;

class MigrationRunnerService extends Component
{
    public function run(string $action, array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        return match ($action) {
            'audit' => $this->runAudit($options),
            'fields' => $this->runFields($options),
            'content' => $this->runContent($options),
            'all' => $this->runAll($options),
            'rollback-info' => $this->runRollbackInfo($options),
            'mismatches' => $this->runMismatches($options),
            default => $this->errorResult(sprintf('Unknown migration action "%s".', $action)),
        };
    }

    public function runAudit(array $options = []): array
    {
        return $this->runAuditStage($this->normalizeOptions($options));
    }

    public function runFields(array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        if (!$options['dryRun'] && !$options['force']) {
            return $this->errorResult('Refusing to mutate field config without --force.');
        }

        return $this->runFieldStage($options);
    }

    public function runContent(array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        if (!$options['dryRun'] && !$options['force']) {
            return $this->errorResult('Refusing to write content without --force.');
        }

        return $this->runContentStage($options);
    }

    public function runAll(array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $messages = [$this->message('warning', 'Running full Link Migrator workflow.')];

        $auditResult = $this->runAuditStage($options);
        $messages = array_merge($messages, $auditResult['messages']);

        if ($auditResult['exitCode'] !== ExitCode::OK) {
            $messages[] = $this->message('error', 'Audit found blocking issues. Resolve them before running the full workflow.');

            return [
                'ok' => false,
                'exitCode' => $auditResult['exitCode'],
                'messages' => $messages,
                'runs' => $auditResult['runs'],
            ];
        }

        if (!$options['dryRun'] && !$options['force']) {
            $messages[] = $this->message('error', 'Refusing to run the full workflow without --force.');

            return [
                'ok' => false,
                'exitCode' => ExitCode::UNSPECIFIED_ERROR,
                'messages' => $messages,
                'runs' => $auditResult['runs'],
            ];
        }

        $audit = $auditResult['audit'] instanceof AuditResult ? $auditResult['audit'] : null;

        $fieldResult = $this->runFieldStage($options, $audit);
        $messages = array_merge($messages, $fieldResult['messages']);
        $runs = array_merge($auditResult['runs'], $fieldResult['runs']);

        if ($fieldResult['exitCode'] !== ExitCode::OK) {
            return [
                'ok' => false,
                'exitCode' => $fieldResult['exitCode'],
                'messages' => $messages,
                'runs' => $runs,
            ];
        }

        if ($options['dryRun']) {
            $messages[] = $this->message('warning', 'Skipping project config apply during dry run.');
        } elseif ($options['applyProjectConfig']) {
            $messages[] = $this->message(
                'warning',
                "Project config files were updated during field migration.\n" .
                "Skipping inline project-config/apply because Craft already holds the config lock in this process.\n" .
                "Run `php craft project-config/apply` separately after this run if your environment requires it."
            );
        } else {
            $messages[] = $this->message('warning', 'Skipping project config apply because it was disabled for this run.');
        }

        $contentResult = $this->runContentStage($options, $audit);
        $messages = array_merge($messages, $contentResult['messages']);
        $runs = array_merge($runs, $contentResult['runs']);

        if ($contentResult['exitCode'] !== ExitCode::OK) {
            return [
                'ok' => false,
                'exitCode' => $contentResult['exitCode'],
                'messages' => $messages,
                'runs' => $runs,
            ];
        }

        $messages[] = $this->message('success', 'Full Link Migrator workflow completed successfully.');

        return [
            'ok' => true,
            'exitCode' => ExitCode::OK,
            'messages' => $messages,
            'runs' => $runs,
        ];
    }

    public function runRollbackInfo(array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $records = HyperToLink::$plugin->getState()->summaries($options['field']);
        $messages = [
            $this->message('warning', 'Rollback information is informational only. Hyper is intentionally left installed.'),
        ];

        foreach ($records as $record) {
            $messages[] = $this->message('info', sprintf(
                '%s | %s | migrated=%d skipped=%d warnings=%d backups=%d last=%s',
                $record['action'],
                $record['fieldHandle'],
                $record['migratedCount'],
                $record['skippedCount'],
                $record['warningCount'],
                $record['backupCount'],
                $record['dateUpdated'] ?? 'n/a'
            ));
        }

        return [
            'ok' => true,
            'exitCode' => ExitCode::OK,
            'messages' => $messages,
            'runs' => [],
            'records' => $records,
        ];
    }

    public function runMismatches(array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('mismatches', true, $options['verbose']);
        $audit = $plugin->getAudit()->buildAudit($options['field']);

        $payload = [
            'summary' => [
                'mismatches' => count($audit->mismatchReferences),
            ],
            'mismatches' => $audit->mismatchReferences,
        ];

        $plugin->getReport()->persist($report, $payload);
        $report->summary = $payload['summary'];

        $messages = [
            $this->message('warning', sprintf('Potential Hyper API mismatches: %d', count($audit->mismatchReferences))),
        ];

        foreach ($audit->mismatchReferences as $mismatch) {
            $messages[] = $this->message('info', sprintf(
                "%s:%d | %s -> %s\n%s\n%s",
                $mismatch['file'],
                $mismatch['line'],
                $mismatch['pattern'],
                $mismatch['replacement'],
                $mismatch['reason'],
                $mismatch['snippet']
            ));
        }

        $messages[] = $this->message('success', "Mismatch report written to {$report->reportPath}");

        return [
            'ok' => count($audit->mismatchReferences) === 0,
            'exitCode' => count($audit->mismatchReferences) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            'messages' => $messages,
            'runs' => [
                'mismatches' => $this->runData('mismatches', $report, $payload),
            ],
        ];
    }

    private function runAuditStage(array $options): array
    {
        $plugin = HyperToLink::$plugin;
        $report = $plugin->getReport()->beginRun('audit', $options['dryRun'], $options['verbose']);
        $audit = $plugin->getAudit()->buildAudit($options['field']);
        $payload = $plugin->getReport()->buildAuditPayload($audit);

        $plugin->getReport()->persist($report, $payload);
        $report->summary = $payload['summary'];

        return [
            'ok' => !$audit->hasBlockingIssues(),
            'exitCode' => $audit->hasBlockingIssues() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            'messages' => [
                $this->message('info', $plugin->getReport()->renderSummary($payload['summary'])),
                $this->message('success', "Audit written to {$report->reportPath}"),
            ],
            'runs' => [
                'audit' => $this->runData('audit', $report, $payload),
            ],
            'audit' => $audit,
        ];
    }

    private function runFieldStage(array $options, ?AuditResult $audit = null): array
    {
        $plugin = HyperToLink::$plugin;
        $audit ??= $plugin->getAudit()->buildAudit($options['field']);
        $report = $plugin->getReport()->beginRun('fields', $options['dryRun'], $options['verbose']);
        $summary = $plugin->getReport()->buildPreflightSummary($audit);
        $messages = $this->preflightMessages('field/config migration', $summary);

        $result = $plugin->getFieldMigration()->migrate($audit, [
            'field' => $options['field'],
            'dryRun' => $options['dryRun'],
            'force' => $options['force'],
            'verbose' => $options['verbose'],
        ]);

        $payload = $plugin->getReport()->buildFieldPayload($result);
        $plugin->getReport()->persist($report, $payload);
        $report->summary = $payload['summary'];

        $messages[] = $this->message('info', $plugin->getReport()->renderSummary($payload['summary']));
        $messages[] = $this->message('success', "Field migration report written to {$report->reportPath}");

        return [
            'ok' => !$result->hasErrors(),
            'exitCode' => $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            'messages' => $messages,
            'runs' => [
                'fields' => $this->runData('fields', $report, $payload),
            ],
        ];
    }

    private function runContentStage(array $options, ?AuditResult $audit = null): array
    {
        $plugin = HyperToLink::$plugin;
        $audit ??= $plugin->getAudit()->buildAudit($options['field']);
        $report = $plugin->getReport()->beginRun('content', $options['dryRun'], $options['verbose']);
        $summary = $plugin->getReport()->buildPreflightSummary($audit);
        $messages = $this->preflightMessages('content migration', $summary);

        $result = $plugin->getContentMigration()->migrate($audit, [
            'field' => $options['field'],
            'dryRun' => $options['dryRun'],
            'force' => $options['force'],
            'verbose' => $options['verbose'],
            'createBackup' => $options['createBackup'],
            'batchSize' => max(1, $options['batchSize']),
        ]);

        $payload = $plugin->getReport()->buildContentPayload($result);
        $plugin->getReport()->persist($report, $payload);
        $report->summary = $payload['summary'];

        $messages[] = $this->message('info', $plugin->getReport()->renderSummary($payload['summary']));
        $messages[] = $this->message('success', "Content migration report written to {$report->reportPath}");

        return [
            'ok' => !$result->hasErrors(),
            'exitCode' => $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            'messages' => $messages,
            'runs' => [
                'content' => $this->runData('content', $report, $payload),
            ],
        ];
    }

    private function normalizeOptions(array $options): array
    {
        return [
            'field' => $options['field'] ?? null,
            'dryRun' => (bool)($options['dryRun'] ?? false),
            'force' => (bool)($options['force'] ?? false),
            'verbose' => (bool)($options['verbose'] ?? false),
            'createBackup' => (bool)($options['createBackup'] ?? false),
            'batchSize' => max(1, (int)($options['batchSize'] ?? 100)),
            'applyProjectConfig' => (bool)($options['applyProjectConfig'] ?? true),
        ];
    }

    private function preflightMessages(string $label, array $summary): array
    {
        $report = HyperToLink::$plugin->getReport();

        return [
            $this->message('info', strtoupper($label)),
            $this->message('info', $report->renderSummary($summary)),
            $this->message('warning', 'Back up the database and project config before non-dry runs.'),
            $this->message('warning', 'Content writes are irreversible without manual restoration from backups.'),
            $this->message('warning', 'Hyper will remain installed; do not uninstall it until reports are clean.'),
        ];
    }

    private function runData(string $action, MigrationReport $report, array $payload): array
    {
        return [
            'action' => $action,
            'reportPath' => $report->reportPath,
            'jsonPath' => $report->jsonPath,
            'summary' => $payload['summary'] ?? [],
            'payload' => $payload,
        ];
    }

    private function errorResult(string $message): array
    {
        return [
            'ok' => false,
            'exitCode' => ExitCode::UNSPECIFIED_ERROR,
            'messages' => [$this->message('error', $message)],
            'runs' => [],
        ];
    }

    private function message(string $type, string $text): array
    {
        return [
            'type' => $type,
            'text' => rtrim($text),
        ];
    }
}
