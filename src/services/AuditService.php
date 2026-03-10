<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\FieldAudit;

class AuditService extends Component
{
    public function buildAudit(?string $fieldHandle = null): AuditResult
    {
        $result = new AuditResult();
        $allFields = Craft::$app->getFields()->getAllFields(false);

        foreach ($allFields as $field) {
            if ($fieldHandle && $field->handle !== $fieldHandle) {
                continue;
            }

            if (!$this->isHyperField($field)) {
                continue;
            }

            $settings = $field->getAttributes();
            $linkTypes = $this->extractLinkTypes($settings);
            $fieldLayouts = $this->extractCustomFieldLayouts($settings);
            $multi = (bool)($settings['multipleLinks'] ?? $settings['allowMultiple'] ?? false);

            $audit = new FieldAudit([
                'fieldId' => (int)$field->id,
                'uid' => (string)$field->uid,
                'handle' => (string)$field->handle,
                'name' => (string)$field->name,
                'multi' => $multi,
                'allowedHyperTypes' => $linkTypes,
                'customFieldLayouts' => $fieldLayouts,
                'containers' => $this->discoverContainers($field),
                'rawSettings' => $settings,
            ]);

            $audit->mapping = HyperToLink::$plugin
                ->getMappingStrategy()
                ->decide($settings, $linkTypes, $multi, $fieldLayouts);
            $audit->warnings = $audit->mapping->warnings;

            $result->fields[] = $audit;
        }

        $result->codeReferences = $this->findCodeReferences();
        $result->notes = [
            'Hyper must remain installed during content migration so existing field values can still be normalized.',
            'Content migration should be rerun in each environment because content is environment-specific.',
            'Craft Link field exists only in Craft 5.3.0+; advanced fields like URL suffix/class/id/rel require newer 5.x versions.',
        ];

        return $result;
    }

    private function isHyperField(object $field): bool
    {
        return is_a($field, 'verbb\\hyper\\fields\\HyperField', true)
            || is_a($field, 'verbb\\hyper\\fields\\Hyper', true)
            || str_contains($field::class, '\\hyper\\fields\\');
    }

    private function extractLinkTypes(array $settings): array
    {
        $types = $settings['linkTypes'] ?? $settings['types'] ?? [];
        $handles = [];

        foreach ((array)$types as $type) {
            if (is_string($type)) {
                $handles[] = $this->normalizeHyperType($type);
            } elseif (is_array($type) && isset($type['type'])) {
                $handles[] = $this->normalizeHyperType((string)$type['type']);
            }
        }

        return array_values(array_unique(array_filter($handles)));
    }

    private function normalizeHyperType(string $type): string
    {
        $type = strtolower($type);
        $type = preg_replace('/^.*\\\\/', '', $type);

        return match ($type) {
            'asset' => 'asset',
            'category' => 'category',
            'email' => 'email',
            'entry' => 'entry',
            'phone' => 'phone',
            'sms' => 'sms',
            'url' => 'url',
            default => $type,
        };
    }

    private function extractCustomFieldLayouts(array $settings): array
    {
        $layouts = $settings['fieldLayouts'] ?? $settings['fields'] ?? [];
        return is_array($layouts) ? $layouts : [];
    }

    private function discoverContainers(object $field): array
    {
        $containers = [];

        foreach (Craft::$app->getFields()->findFieldUsages($field) as $usage) {
            $containers[] = $usage;
        }

        return $containers;
    }

    private function findCodeReferences(): array
    {
        $patterns = [
            '.url',
            '.text',
            '.target',
            'getLink(',
            'linkValue',
            '.type',
            'getElement(',
            'hasElement(',
            'Hyper',
        ];

        $roots = [
            Craft::getAlias('@root/templates'),
            Craft::getAlias('@root/modules'),
            Craft::getAlias('@root/src'),
            Craft::getAlias('@root/config'),
        ];

        $references = [];

        foreach ($roots as $root) {
            if (!$root || !is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $contents = @file($fileInfo->getPathname());
                if ($contents === false) {
                    continue;
                }

                foreach ($contents as $lineNumber => $line) {
                    foreach ($patterns as $pattern) {
                        if (str_contains($line, $pattern)) {
                            $references[] = [
                                'file' => $fileInfo->getPathname(),
                                'line' => $lineNumber + 1,
                                'pattern' => $pattern,
                                'snippet' => trim($line),
                            ];
                        }
                    }
                }
            }
        }

        return $references;
    }
}
