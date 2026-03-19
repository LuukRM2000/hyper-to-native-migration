<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\db\ElementQuery;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\ContentMigrationResult;
use lm2k\hypertolink\models\FieldAudit;
use lm2k\hypertolink\models\MappingDecision;

class ContentMigrationService extends Component
{
    private const NATIVE_LINK_TYPES = ['asset', 'category', 'email', 'entry', 'phone', 'sms', 'url'];
    private array $fieldContextCache = [];
    private array $rawContentCache = [];
    private array $migratedStateCache = [];

    public function migrate(AuditResult $audit, array $options): ContentMigrationResult
    {
        $result = new ContentMigrationResult();
        $elements = Craft::$app->getElements();
        $batchSize = (int)($options['batchSize'] ?? 100);

        foreach ($audit->fields as $fieldAudit) {
            if ($fieldAudit->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                $result->addSkipped([
                    'field' => $fieldAudit->handle,
                    'reason' => $fieldAudit->mapping->unsupportedReasons,
                ]);
                continue;
            }

            $layoutIds = $this->extractLayoutIds($fieldAudit->containers);
            foreach ($this->buildElementQueries($fieldAudit->containers) as $query) {
                foreach ($query->batch($batchSize) as $batch) {
                    $this->primeBatchCaches($fieldAudit, $batch);

                    foreach ($batch as $element) {
                        $fieldContext = $this->resolveFieldContext($element, $fieldAudit->fieldId);
                        $runtimeFieldHandle = $fieldContext['runtimeHandle'] ?? null;
                        if ($runtimeFieldHandle === null || !$this->elementSupportsField($element, $runtimeFieldHandle, $layoutIds)) {
                            continue;
                        }

                        try {
                            if ($this->isMigratedInBatch($element)) {
                                $this->recordSkipped($result, $options, $fieldAudit->handle, $element, 'Already migrated.');
                                continue;
                            }

                            $value = $this->getStoredFieldValue($element, $fieldAudit, $fieldContext);
                            if ($this->isNativeLinkValue($value)) {
                                $this->recordSkipped($result, $options, $fieldAudit->handle, $element, 'Already a native Link value.');
                                continue;
                            }

                            if ($this->isEmptyHyperValue($value)) {
                                $this->recordSkipped($result, $options, $fieldAudit->handle, $element, 'Empty value.');
                                continue;
                            }

                            $conversion = $this->convertHyperValue($value, $element->siteId);
                            if ($conversion['status'] === 'unsupported') {
                                $this->recordWarning($result, $options, $fieldAudit->handle, $element, $conversion['warnings'], $conversion['backup']);
                                continue;
                            }

                            $backupPath = null;
                            if (empty($options['dryRun']) && !empty($options['createBackup'])) {
                                $backupPath = HyperToLink::$plugin->getState()->writeBackup('content', $fieldAudit->handle, $element, $conversion['backup']);
                                $result->addBackup($backupPath);
                            }

                            if (!empty($options['dryRun'])) {
                                $result->addMigrated([
                                    'field' => $fieldAudit->handle,
                                    'elementId' => $element->id,
                                    'siteId' => $element->siteId,
                                    'mode' => 'dry-run',
                                    'payload' => $conversion['summary'],
                                    'backupPath' => $backupPath,
                                ]);
                                continue;
                            }

                            $transaction = Craft::$app->getDb()->beginTransaction();
                            try {
                                $element->setFieldValue($runtimeFieldHandle, $conversion['payload']);
                                if (!$elements->saveElement($element, false, false, false)) {
                                    throw new \RuntimeException('saveElement() returned false.');
                                }

                                HyperToLink::$plugin->getState()->markMigrated(
                                    'content',
                                    $fieldAudit->handle,
                                    $element,
                                    $conversion['warnings'],
                                    $conversion['backup'],
                                    $backupPath
                                );

                                $transaction->commit();
                            } catch (\Throwable $exception) {
                                $transaction->rollBack();
                                throw $exception;
                            }

                            $result->addMigrated([
                                'field' => $fieldAudit->handle,
                                'elementId' => $element->id,
                                'siteId' => $element->siteId,
                                'warnings' => $conversion['warnings'],
                                'backupPath' => $backupPath,
                            ]);
                        } catch (\Throwable $e) {
                            $result->recordError([
                                'field' => $fieldAudit->handle,
                                'elementId' => $element->id ?? null,
                                'reason' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return ElementQuery[]
     */
    private function buildElementQueries(array $containers): array
    {
        $classes = [];

        foreach ($containers as $container) {
            if (
                is_object($container) &&
                isset($container->type) &&
                is_string($container->type) &&
                is_a($container->type, ElementInterface::class, true)
            ) {
                $classes[$container->type] = true;
            }
        }

        if (empty($classes)) {
            $classes[Entry::class] = true;
        }

        $queries = [];
        foreach (array_keys($classes) as $class) {
            /** @var ElementQuery $query */
            $query = $class::find()->status(null)->site('*')->drafts(null)->provisionalDrafts(null)->trashed(null);
            $queries[] = $query;
        }

        return $queries;
    }

    private function extractLayoutIds(array $containers): array
    {
        $layoutIds = [];

        foreach ($containers as $container) {
            if (is_object($container) && isset($container->id) && is_numeric($container->id)) {
                $layoutIds[(int)$container->id] = true;
            }
        }

        return array_keys($layoutIds);
    }

    private function primeBatchCaches(FieldAudit $fieldAudit, array $batch): void
    {
        $this->rawContentCache = [];
        $this->migratedStateCache = HyperToLink::$plugin->getState()->migratedMap('content', $fieldAudit->handle, $batch);

        $condition = $this->buildElementSiteCondition($batch);
        if ($condition === null) {
            return;
        }

        $rows = (new Query())
            ->select(['elementId', 'siteId', 'content'])
            ->from(['{{%elements_sites}}'])
            ->where($condition)
            ->all();

        foreach ($rows as $row) {
            $cacheKey = $row['elementId'] . ':' . $row['siteId'];
            $content = $row['content'] ?? null;

            if (!is_string($content) || $content === '') {
                $this->rawContentCache[$cacheKey] = null;
                continue;
            }

            $decoded = json_decode($content, true);
            $this->rawContentCache[$cacheKey] = is_array($decoded) ? $decoded : null;
        }

        foreach ($batch as $element) {
            if ($element instanceof ElementInterface) {
                $this->rawContentCache[$this->elementKey($element)] ??= null;
            }
        }
    }

    private function buildElementSiteCondition(array $elements): ?array
    {
        $pairs = [];

        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || $element->id === null || $element->siteId === null) {
                continue;
            }

            $pairs[$this->elementKey($element)] = [
                'and',
                ['elementId' => (int)$element->id],
                ['siteId' => (int)$element->siteId],
            ];
        }

        if ($pairs === []) {
            return null;
        }

        return ['or', ...array_values($pairs)];
    }

    private function isMigratedInBatch(ElementInterface $element): bool
    {
        return $this->migratedStateCache[$this->elementKey($element)] ?? false;
    }

    private function elementSupportsField(ElementInterface $element, string $fieldHandle, array $layoutIds): bool
    {
        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return false;
        }

        if ($layoutIds !== [] && !in_array((int)$fieldLayout->id, $layoutIds, true)) {
            return false;
        }

        return $fieldLayout->getFieldByHandle($fieldHandle) !== null;
    }

    private function resolveFieldContext(ElementInterface $element, int $fieldId): ?array
    {
        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return null;
        }

        $cacheKey = (int)$fieldLayout->id . ':' . $fieldId;
        if (array_key_exists($cacheKey, $this->fieldContextCache)) {
            return $this->fieldContextCache[$cacheKey];
        }

        foreach ($fieldLayout->getTabs() as $tab) {
            foreach ($tab->getElements() as $layoutElement) {
                if (!method_exists($layoutElement, 'getField')) {
                    continue;
                }

                $field = $layoutElement->getField();
                if ($field && (int)$field->id === $fieldId) {
                    return $this->fieldContextCache[$cacheKey] = [
                        'runtimeHandle' => (string)$field->handle,
                        'layoutElementUid' => isset($layoutElement->uid) ? (string)$layoutElement->uid : null,
                    ];
                }
            }
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ((int)$field->id === $fieldId) {
                return $this->fieldContextCache[$cacheKey] = [
                    'runtimeHandle' => (string)$field->handle,
                    'layoutElementUid' => null,
                ];
            }
        }

        return $this->fieldContextCache[$cacheKey] = null;
    }

    private function getStoredFieldValue(ElementInterface $element, FieldAudit $fieldAudit, array $fieldContext): mixed
    {
        $fieldHandle = $fieldContext['runtimeHandle'] ?? null;
        if (!is_string($fieldHandle) || $fieldHandle === '') {
            return null;
        }

        $values = $element->getSerializedFieldValues([$fieldHandle]);
        $value = $this->unwrapStoredFieldValue($values[$fieldHandle] ?? null);

        if (!$this->isEmptyHyperValue($value)) {
            return $value;
        }

        $rawKeys = array_values(array_filter([
            $fieldContext['layoutElementUid'] ?? null,
            $fieldAudit->uid,
        ], static fn($key) => is_string($key) && $key !== ''));

        return $this->unwrapStoredFieldValue($this->getRawFieldValue($element, $rawKeys));
    }

    private function getRawFieldValue(ElementInterface $element, array $rawKeys): mixed
    {
        $cacheKey = $this->elementKey($element);
        if (!array_key_exists($cacheKey, $this->rawContentCache)) {
            $content = (new Query())
                ->select(['content'])
                ->from(['{{%elements_sites}}'])
                ->where([
                    'elementId' => $element->id,
                    'siteId' => $element->siteId,
                ])
                ->scalar();

            if (!is_string($content) || $content === '') {
                $this->rawContentCache[$cacheKey] = null;
            } else {
                $decoded = json_decode($content, true);
                $this->rawContentCache[$cacheKey] = is_array($decoded) ? $decoded : null;
            }
        }

        $decoded = $this->rawContentCache[$cacheKey];
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                return $decoded[$key];
            }
        }

        return null;
    }

    private function recordSkipped(
        ContentMigrationResult $result,
        array $options,
        string $fieldHandle,
        ElementInterface $element,
        string $reason
    ): void {
        if (empty($options['dryRun'])) {
            HyperToLink::$plugin->getState()->markSkipped('content', $fieldHandle, $element, $reason);
        }

        $result->addSkipped([
            'field' => $fieldHandle,
            'elementId' => $element->id,
            'reason' => $reason,
        ]);
    }

    private function recordWarning(
        ContentMigrationResult $result,
        array $options,
        string $fieldHandle,
        ElementInterface $element,
        array $warnings,
        array $backup
    ): void {
        if (empty($options['dryRun'])) {
            HyperToLink::$plugin->getState()->markWarning('content', $fieldHandle, $element, $warnings, $backup);
        }

        $result->addWarning([
            'field' => $fieldHandle,
            'elementId' => $element->id,
            'warnings' => $warnings,
        ]);
    }

    private function elementKey(ElementInterface $element): string
    {
        return $element->id . ':' . $element->siteId;
    }

    private function unwrapStoredFieldValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = $this->decodeSerializedJson($value);
            if ($decoded !== null) {
                return $this->unwrapStoredFieldValue($decoded);
            }

            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($this->looksLikeNativeLinkPayload($value)) {
            $decoded = $this->decodeSerializedJson((string)$value['value']);
            if ($decoded !== null) {
                return $this->unwrapStoredFieldValue($decoded);
            }

            return $value;
        }

        if (array_is_list($value) && count($value) === 1) {
            return $this->unwrapStoredFieldValue($value[0]);
        }

        return $value;
    }

    private function decodeSerializedJson(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || (!str_starts_with($value, '[') && !str_starts_with($value, '{'))) {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function looksLikeNativeLinkPayload(array $value): bool
    {
        return isset($value['type'], $value['value'])
            && is_string($value['type'])
            && in_array($value['type'], self::NATIVE_LINK_TYPES, true);
    }

    private function isNativeLinkValue(mixed $value): bool
    {
        return is_array($value)
            && $this->looksLikeNativeLinkPayload($value)
            && $this->decodeSerializedJson((string)$value['value']) === null;
    }

    private function isEmptyHyperValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value) && isset($value['value']) && trim((string)$value['value']) === '') {
            return true;
        }

        if (is_object($value) && method_exists($value, 'isEmpty')) {
            return (bool)$value->isEmpty();
        }

        return false;
    }

    private function convertHyperValue(mixed $value, ?int $elementSiteId = null): array
    {
        $backup = $this->backupPayload($value);
        $warnings = [];

        $type = $this->normalizeType($this->readHyperProperty($value, ['type', 'linkType', 'handle']));
        $text = $this->readHyperProperty($value, ['text', 'label', 'linkText']);
        $target = $this->readHyperProperty($value, ['target', 'newWindow']);
        $urlSuffix = $this->readHyperProperty($value, ['urlSuffix']);
        $title = $this->readHyperProperty($value, ['title']);
        $class = $this->readHyperProperty($value, ['class', 'classes']);
        $id = $this->readHyperProperty($value, ['id']);
        $rel = $this->readHyperProperty($value, ['rel']);
        $customFields = $this->readHyperProperty($value, ['fields']);
        $linkValue = $this->readHyperProperty($value, ['linkValue', 'value', 'url']);
        $linkedSiteId = $this->readHyperProperty($value, ['linkSiteId', 'siteId']) ?? $elementSiteId;
        $element = $this->readElement($value) ?? $this->resolveLinkedElement($type, $linkValue, $linkedSiteId);

        if ($customFields) {
            $warnings[] = 'Custom Hyper link fields were preserved in backup only.';
        }

        $nativeType = match ($type) {
            'asset' => 'asset',
            'category' => 'category',
            'email' => 'email',
            'entry' => 'entry',
            'phone' => 'phone',
            'sms' => 'sms',
            'url' => 'url',
            default => null,
        };

        if ($nativeType === null) {
            if (!$this->canFallbackToUrl($linkValue)) {
                return [
                    'status' => 'unsupported',
                    'warnings' => [sprintf(
                        'Unsupported Hyper link type for content migration: %s. No safe URL-like fallback value was found.',
                        $type ?: 'unknown'
                    )],
                    'backup' => $backup,
                ];
            }

            $nativeType = 'url';
            $warnings[] = sprintf(
                'Custom or unsupported Hyper link type "%s" was migrated as a native URL link.',
                $type ?: 'unknown'
            );
        }

        if (in_array($type, ['entry', 'asset', 'category'], true)) {
            if (!$element) {
                return [
                    'status' => 'unsupported',
                    'warnings' => ['Linked element is missing or invalid.'],
                    'backup' => $backup,
                ];
            }

            $linkValue = $element->id;
        }

        $payload = array_filter([
            'type' => $nativeType,
            'value' => $linkValue,
            'label' => $text,
            'target' => $this->normalizeTarget($target),
            'urlSuffix' => $urlSuffix,
            'title' => $title,
            'class' => $class,
            'id' => $id,
            'rel' => $rel,
        ], static fn($item) => $item !== null && $item !== '');

        return [
            'status' => 'ok',
            'payload' => $payload,
            'summary' => [
                'type' => $nativeType,
                'value' => $linkValue,
                'label' => $text,
                'target' => $this->normalizeTarget($target),
            ],
            'warnings' => $warnings,
            'backup' => $backup,
        ];
    }

    private function normalizeType(mixed $type): string
    {
        $value = strtolower((string)$type);
        return preg_replace('/^.*\\\\/', '', $value);
    }

    private function readHyperProperty(mixed $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                return $value[$key];
            }

            if (is_object($value)) {
                if (isset($value->$key)) {
                    return $value->$key;
                }

                $getter = 'get' . ucfirst($key);
                if (method_exists($value, $getter)) {
                    return $value->$getter();
                }
            }
        }

        return null;
    }

    private function readElement(mixed $value): ?ElementInterface
    {
        $element = $this->readHyperProperty($value, ['element']);
        if ($element instanceof ElementInterface) {
            return $element;
        }

        if (is_object($value) && method_exists($value, 'getElement')) {
            $candidate = $value->getElement();
            return $candidate instanceof ElementInterface ? $candidate : null;
        }

        return null;
    }

    private function resolveLinkedElement(string $type, mixed $value, mixed $siteId): ?ElementInterface
    {
        $elementId = match (true) {
            is_numeric($value) => (int)$value,
            is_array($value) && count($value) === 1 && is_numeric(reset($value)) => (int)reset($value),
            default => null,
        };

        if (!$elementId) {
            return null;
        }

        $elementClass = match ($type) {
            'asset' => Asset::class,
            'category' => Category::class,
            'entry' => Entry::class,
            default => null,
        };

        if ($elementClass === null) {
            return null;
        }

        /** @var ElementQuery $query */
        $query = $elementClass::find()->id($elementId)->status(null);

        if (method_exists($query, 'siteId') && is_numeric($siteId)) {
            $query->siteId((int)$siteId);
        }

        foreach (['drafts', 'provisionalDrafts', 'trashed', 'revisions'] as $method) {
            if (method_exists($query, $method)) {
                $query->{$method}(null);
            }
        }

        $element = $query->one();
        return $element instanceof ElementInterface ? $element : null;
    }

    private function canFallbackToUrl(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return true;
        }

        return str_starts_with($value, '/')
            || str_starts_with($value, '#')
            || str_starts_with($value, '?')
            || str_starts_with($value, 'mailto:')
            || str_starts_with($value, 'tel:')
            || str_starts_with($value, 'sms:');
    }

    private function normalizeTarget(mixed $target): ?string
    {
        if (is_string($target)) {
            $target = trim($target);
            return $target !== '' ? $target : null;
        }

        if (is_bool($target)) {
            return $target ? '_blank' : null;
        }

        if (is_numeric($target)) {
            return ((int)$target) === 1 ? '_blank' : null;
        }

        return null;
    }

    private function backupPayload(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        if ($value instanceof \JsonSerializable) {
            return (array)$value->jsonSerialize();
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['unserializable' => get_debug_type($value)];
        }

        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : ['scalar' => $decoded];
    }
}
