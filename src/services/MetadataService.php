<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use lm2k\hypertolink\models\FieldAudit;

class MetadataService extends Component
{
    private const PROJECT_CONFIG_PATH = 'hyperToLink.migratedFields';

    public function recordFieldMigration(object $field, FieldAudit $audit): void
    {
        $uid = (string)($field->uid ?? $audit->uid);
        if ($uid === '') {
            throw new \RuntimeException('Unable to persist migration metadata for a field without a UID.');
        }

        Craft::$app->getProjectConfig()->set(
            $this->projectConfigPath($uid),
            [
                'handle' => (string)($field->handle ?? $audit->handle),
                'name' => (string)($field->name ?? $audit->name),
                'uid' => $uid,
                'allowedHyperTypes' => array_values($audit->allowedHyperTypes),
                'customFieldLayouts' => array_values($audit->customFieldLayouts),
                'mapping' => $audit->mapping->toArray(),
                'recordedAt' => gmdate(DATE_ATOM),
            ],
            sprintf('Record Link Migrator metadata for field "%s".', (string)($field->handle ?? $audit->handle))
        );
    }

    public function getMigratedFields(?string $fieldHandle = null): array
    {
        $fields = Craft::$app->getProjectConfig()->get(self::PROJECT_CONFIG_PATH) ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $filtered = [];
        foreach ($fields as $uid => $field) {
            if (!is_array($field)) {
                continue;
            }

            $handle = (string)($field['handle'] ?? '');
            if ($fieldHandle !== null && $handle !== $fieldHandle) {
                continue;
            }

            $field['uid'] = (string)($field['uid'] ?? $uid);
            $filtered[(string)$uid] = $field;
        }

        ksort($filtered, SORT_STRING);

        return $filtered;
    }

    private function projectConfigPath(string $uid): string
    {
        return self::PROJECT_CONFIG_PATH . '.' . $uid;
    }
}
