<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\fields\data\LinkData;
use craft\fields\linktypes\Asset as AssetLinkType;
use craft\fields\linktypes\Category as CategoryLinkType;
use craft\fields\linktypes\Email as EmailLinkType;
use craft\fields\linktypes\Entry as EntryLinkType;
use craft\fields\linktypes\Phone as PhoneLinkType;
use craft\fields\linktypes\Sms as SmsLinkType;
use craft\fields\linktypes\Url as UrlLinkType;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\ContentMigrationResult;
use lm2k\hypertolink\models\MappingDecision;

class ContentMigrationService extends Component
{
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
                    foreach ($batch as $element) {
                        if (!$this->elementSupportsField($element, $fieldAudit->handle, $layoutIds)) {
                            continue;
                        }

                        try {
                            if (HyperToLink::$plugin->getState()->isMigrated('content', $fieldAudit->handle, $element)) {
                                $result->addSkipped([
                                    'field' => $fieldAudit->handle,
                                    'elementId' => $element->id,
                                    'reason' => 'Already migrated.',
                                ]);
                                continue;
                            }

                            $value = $element->getFieldValue($fieldAudit->handle);
                            if ($this->isEmptyHyperValue($value)) {
                                if (empty($options['dryRun'])) {
                                    HyperToLink::$plugin->getState()->markSkipped('content', $fieldAudit->handle, $element, 'Empty value.');
                                }
                                $result->addSkipped([
                                    'field' => $fieldAudit->handle,
                                    'elementId' => $element->id,
                                    'reason' => 'Empty value.',
                                ]);
                                continue;
                            }

                            $conversion = $this->convertHyperValue($value);
                            if ($conversion['status'] === 'unsupported') {
                                if (empty($options['dryRun'])) {
                                    HyperToLink::$plugin->getState()->markWarning('content', $fieldAudit->handle, $element, $conversion['warnings'], $conversion['backup']);
                                }
                                $result->addWarning([
                                    'field' => $fieldAudit->handle,
                                    'elementId' => $element->id,
                                    'warnings' => $conversion['warnings'],
                                ]);
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

                            $element->setFieldValue($fieldAudit->handle, $conversion['payload']);
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
            $classes[\craft\elements\Entry::class] = true;
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

    private function isEmptyHyperValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_object($value) && method_exists($value, 'isEmpty')) {
            return (bool)$value->isEmpty();
        }

        return false;
    }

    private function convertHyperValue(mixed $value): array
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
        $element = $this->readElement($value);

        if ($customFields) {
            $warnings[] = 'Custom Hyper link fields were preserved in backup only.';
        }

        $linkTypeClass = match ($type) {
            'asset' => AssetLinkType::class,
            'category' => CategoryLinkType::class,
            'email' => EmailLinkType::class,
            'entry' => EntryLinkType::class,
            'phone' => PhoneLinkType::class,
            'sms' => SmsLinkType::class,
            'url' => UrlLinkType::class,
            default => null,
        };

        if ($linkTypeClass === null) {
            if (!$linkValue || !is_scalar($linkValue)) {
                return [
                    'status' => 'unsupported',
                    'warnings' => [sprintf('Unsupported Hyper link type for content migration: %s', $type ?: 'unknown')],
                    'backup' => $backup,
                ];
            }

            $linkTypeClass = UrlLinkType::class;
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

        $payloadConfig = array_filter([
            'label' => $text,
            'target' => $target ? '_blank' : null,
            'urlSuffix' => $urlSuffix,
            'title' => $title,
            'class' => $class,
            'id' => $id,
            'rel' => $rel,
            'element' => $element,
        ], static fn($item) => $item !== null && $item !== '');

        $payload = new LinkData($linkValue, $linkTypeClass, $payloadConfig);

        return [
            'status' => 'ok',
            'payload' => $payload,
            'summary' => [
                'type' => $type,
                'value' => $linkValue,
                'label' => $text,
                'target' => $target ? '_blank' : null,
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
