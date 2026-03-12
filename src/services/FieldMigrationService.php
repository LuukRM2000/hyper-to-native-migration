<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\fields\Link;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\FieldMigrationResult;
use lm2k\hypertolink\models\MappingDecision;

class FieldMigrationService extends Component
{
    public function migrate(AuditResult $audit, array $options): FieldMigrationResult
    {
        $result = new FieldMigrationResult();
        $fieldsService = Craft::$app->getFields();

        foreach ($audit->fields as $fieldAudit) {
            if ($fieldAudit->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                $result->skipped[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => $fieldAudit->mapping->unsupportedReasons,
                ];
                continue;
            }

            try {
                $existing = $fieldsService->getFieldById($fieldAudit->fieldId);
                if (!$existing) {
                    $result->errors[] = [
                        'field' => $fieldAudit->handle,
                        'reason' => 'Field no longer exists.',
                    ];
                    continue;
                }

                $linkField = $this->buildLinkFieldConfig($existing, $fieldAudit->mapping);

                if (!empty($options['dryRun'])) {
                    $result->migrated[] = [
                        'field' => $fieldAudit->handle,
                        'uid' => $fieldAudit->uid,
                        'mode' => 'dry-run',
                        'config' => $linkField->toArray(),
                    ];
                    continue;
                }

                $transaction = Craft::$app->getDb()->beginTransaction();
                try {
                    $saved = $fieldsService->saveField($linkField, false);
                    if (!$saved) {
                        throw new \RuntimeException('saveField() returned false.');
                    }

                    $transaction->commit();
                } catch (\Throwable $e) {
                    $transaction->rollBack();
                    throw $e;
                }

                $result->migrated[] = [
                    'field' => $fieldAudit->handle,
                    'uid' => $fieldAudit->uid,
                    'mode' => 'write',
                    'status' => $fieldAudit->mapping->status,
                ];
            } catch (\Throwable $e) {
                $result->errors[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    private function buildLinkFieldConfig(object $existingField, MappingDecision $mapping): Link
    {
        $config = [
            'name' => $existingField->name,
            'handle' => $existingField->handle,
            'types' => $mapping->craftLinkTypes,
            'showLabelField' => true,
        ];

        foreach (['instructions', 'translationMethod', 'translationKeyFormat', 'searchable', 'required', 'tip', 'warning', 'uid'] as $property) {
            try {
                $config[$property] = $existingField->{$property};
            } catch (\Throwable) {
                // Hyper/Craft field models do not expose all historical field properties on Craft 5.
            }
        }

        if ($this->supportsAdvancedFields()) {
            $config['advancedFields'] = array_values(array_filter(
                $mapping->advancedFields,
                static fn(string $field) => $field !== 'label'
            ));
        }

        /** @var Link $field */
        $field = Craft::createObject(array_merge(['class' => Link::class], $config));
        $field->id = $existingField->id;

        return $field;
    }

    private function supportsAdvancedFields(): bool
    {
        return property_exists(Link::class, 'advancedFields');
    }
}
