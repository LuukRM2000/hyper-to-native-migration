<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\console\Controller;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\ContentMigrationResult;
use lm2k\hypertolink\models\FieldMigrationResult;
use lm2k\hypertolink\models\MigrationReport;

class ReportService extends Component
{
    public function beginRun(string $action, bool $dryRun, bool $verbose): MigrationReport
    {
        $runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $baseDir = Craft::getAlias('@storage/runtime/link-migrator');
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        return new MigrationReport([
            'runId' => $runId,
            'action' => $action,
            'dryRun' => $dryRun,
            'verbose' => $verbose,
            'reportPath' => $baseDir . DIRECTORY_SEPARATOR . $runId . '-' . $action . '.log',
            'jsonPath' => $baseDir . DIRECTORY_SEPARATOR . $runId . '-' . $action . '.json',
        ]);
    }

    public function writeAudit(MigrationReport $report, AuditResult $audit, Controller $controller): void
    {
        $payload = $this->buildAuditPayload($audit);

        $this->persist($report, $payload);
        $controller->stdout($this->renderSummary($payload['summary']));
    }

    public function writePreflight(MigrationReport $report, AuditResult $audit, Controller $controller, string $label): void
    {
        $summary = $this->buildPreflightSummary($audit);

        $controller->stdout(strtoupper($label) . "\n");
        $controller->stdout($this->renderSummary($summary));
        $controller->stdout("Warnings:\n");
        $controller->stdout("- Back up the database and project config before non-dry runs.\n");
        $controller->stdout("- Content writes are irreversible without manual restoration from backups.\n");
        $controller->stdout("- Hyper will remain installed; do not uninstall it until reports are clean.\n\n");
    }

    public function writeFieldResult(MigrationReport $report, FieldMigrationResult $result, Controller $controller): void
    {
        $payload = $this->buildFieldPayload($result);

        $this->persist($report, $payload);
        $controller->stdout($this->renderSummary($payload['summary']));
    }

    public function writeContentResult(MigrationReport $report, ContentMigrationResult $result, Controller $controller): void
    {
        $payload = $this->buildContentPayload($result);

        $this->persist($report, $payload);
        $controller->stdout($this->renderSummary($payload['summary']));
    }

    public function buildAuditPayload(AuditResult $audit): array
    {
        return [
            'summary' => [
                'fields' => count($audit->fields),
                'supported' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'supported')),
                'partial' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'partial')),
                'unsupported' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'unsupported')),
                'references' => count($audit->codeReferences),
                'mismatches' => count($audit->mismatchReferences),
            ],
            'fields' => array_map(function ($field) {
                return [
                    'fieldId' => $field->fieldId,
                    'uid' => $field->uid,
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'multi' => $field->multi,
                    'allowedHyperTypes' => $field->allowedHyperTypes,
                    'customFieldLayouts' => $field->customFieldLayouts,
                    'warnings' => $field->warnings,
                    'mapping' => $field->mapping->toArray(),
                ];
            }, $audit->fields),
            'references' => $audit->codeReferences,
            'mismatches' => $audit->mismatchReferences,
            'notes' => $audit->notes,
        ];
    }

    public function buildPreflightSummary(AuditResult $audit): array
    {
        return [
            'fields' => count($audit->fields),
            'supported' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'supported')),
            'partial' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'partial')),
            'unsupported' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'unsupported')),
        ];
    }

    public function buildFieldPayload(FieldMigrationResult $result): array
    {
        return [
            'summary' => [
                'migrated' => count($result->migrated),
                'skipped' => count($result->skipped),
                'warnings' => count($result->warnings),
                'errors' => count($result->errors),
            ],
            'migrated' => $result->migrated,
            'skipped' => $result->skipped,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
        ];
    }

    public function buildContentPayload(ContentMigrationResult $result): array
    {
        return [
            'summary' => [
                'migrated' => $result->migratedCount,
                'skipped' => $result->skippedCount,
                'warnings' => $result->warningCount,
                'errors' => $result->errorCount,
                'backups' => $result->backupCount,
            ],
            'detailLimit' => $result->detailLimit(),
            'truncatedDetails' => [
                'migrated' => max(0, $result->migratedCount - count($result->migrated)),
                'skipped' => max(0, $result->skippedCount - count($result->skipped)),
                'warnings' => max(0, $result->warningCount - count($result->warnings)),
                'errors' => max(0, $result->errorCount - count($result->errors)),
                'backups' => max(0, $result->backupCount - count($result->backups)),
            ],
            'migrated' => $result->migrated,
            'skipped' => $result->skipped,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
            'backups' => $result->backups,
        ];
    }

    public function persist(MigrationReport $report, array $payload): void
    {
        file_put_contents($report->jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        file_put_contents($report->reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    public function renderSummary(array $summary): string
    {
        $lines = [];
        foreach ($summary as $key => $value) {
            $lines[] = sprintf("%s: %s", $key, $value);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }
}
