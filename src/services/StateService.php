<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use lm2k\hypertolink\records\MigrationRecord;

class StateService extends Component
{
    public function isMigrated(string $action, string $fieldHandle, ElementInterface $element): bool
    {
        return MigrationRecord::find()
            ->where([
                'action' => $action,
                'fieldHandle' => $fieldHandle,
                'ownerId' => $element->id,
                'siteId' => $element->siteId,
                'status' => 'migrated',
            ])
            ->exists();
    }

    public function migratedMap(string $action, string $fieldHandle, array $elements): array
    {
        $ownerIds = [];
        $siteIds = [];

        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || $element->id === null || $element->siteId === null) {
                continue;
            }

            $ownerIds[(int)$element->id] = true;
            $siteIds[(int)$element->siteId] = true;
        }

        if ($ownerIds === [] || $siteIds === []) {
            return [];
        }

        $records = MigrationRecord::find()
            ->select(['ownerId', 'siteId'])
            ->where([
                'action' => $action,
                'fieldHandle' => $fieldHandle,
                'status' => 'migrated',
                'ownerId' => array_keys($ownerIds),
                'siteId' => array_keys($siteIds),
            ])
            ->asArray()
            ->all();

        $map = [];
        foreach ($records as $record) {
            $map[$record['ownerId'] . ':' . $record['siteId']] = true;
        }

        return $map;
    }

    public function markMigrated(
        string $action,
        string $fieldHandle,
        ElementInterface $element,
        array $warnings = [],
        array $backup = [],
        ?string $backupPath = null
    ): void
    {
        $this->saveRecord($action, $fieldHandle, $element, 'migrated', $warnings, $backup, $backupPath);
    }

    public function markSkipped(string $action, string $fieldHandle, ElementInterface $element, string $reason): void
    {
        $this->saveRecord($action, $fieldHandle, $element, 'skipped', [$reason], [], null);
    }

    public function markWarning(string $action, string $fieldHandle, ElementInterface $element, array $warnings, array $backup = []): void
    {
        $this->saveRecord($action, $fieldHandle, $element, 'warning', $warnings, $backup, null);
    }

    public function writeBackup(string $action, string $fieldHandle, ElementInterface $element, array $backup): string
    {
        $baseDir = Craft::getAlias('@storage/runtime/link-migrator/backups');
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $path = sprintf(
            '%s/%s-%s-%s-%s.json',
            $baseDir,
            $action,
            $fieldHandle,
            $element->id,
            $element->siteId
        );

        file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return $path;
    }

    public function summaries(?string $fieldHandle = null): array
    {
        $query = MigrationRecord::find()
            ->select([
                'action',
                'fieldHandle',
                'SUM(CASE WHEN status = "migrated" THEN 1 ELSE 0 END) AS migratedCount',
                'SUM(CASE WHEN status = "skipped" THEN 1 ELSE 0 END) AS skippedCount',
                'SUM(CASE WHEN status = "warning" THEN 1 ELSE 0 END) AS warningCount',
                'SUM(CASE WHEN backupPath IS NOT NULL AND backupPath != "" THEN 1 ELSE 0 END) AS backupCount',
                'MAX(dateUpdated) AS dateUpdated',
            ])
            ->groupBy(['action', 'fieldHandle'])
            ->asArray();

        if ($fieldHandle) {
            $query->andWhere(['fieldHandle' => $fieldHandle]);
        }

        return $query->all();
    }

    private function saveRecord(
        string $action,
        string $fieldHandle,
        ElementInterface $element,
        string $status,
        array $warnings,
        array $backup,
        ?string $backupPath
    ): void {
        $record = MigrationRecord::findOne([
            'action' => $action,
            'fieldHandle' => $fieldHandle,
            'ownerId' => $element->id,
            'siteId' => $element->siteId,
        ]) ?? new MigrationRecord();

        $record->action = $action;
        $record->fieldHandle = $fieldHandle;
        $record->ownerId = $element->id;
        $record->siteId = $element->siteId;
        $record->ownerUid = $element->uid;
        $record->status = $status;
        $record->warningsJson = json_encode(array_values($warnings), JSON_UNESCAPED_SLASHES);
        $record->backupJson = $backup ? json_encode($backup, JSON_UNESCAPED_SLASHES) : null;
        $record->backupPath = $backupPath;
        if (!$record->save(false)) {
            throw new \RuntimeException(sprintf(
                'Failed to persist migration state for field "%s" on element %s.',
                $fieldHandle,
                $element->id ?? 'unknown'
            ));
        }
    }
}
