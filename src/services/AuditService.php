<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\FieldAudit;

class AuditService extends Component
{
    private const MISMATCH_PATTERNS = [
        [
            'pattern' => '.text',
            'replacement' => '.label',
            'reason' => 'Hyper commonly exposes link text via `.text`; Craft LinkData uses `.label`.',
        ],
        [
            'pattern' => '.linkText',
            'replacement' => '.label',
            'reason' => 'Hyper `linkText` should usually become Craft LinkData `.label`.',
        ],
        [
            'pattern' => 'linkValue',
            'replacement' => 'value or url',
            'reason' => 'Hyper `linkValue` maps to `value` for the raw stored value or `url` for rendered href output.',
        ],
        [
            'pattern' => 'getLink(',
            'replacement' => 'link.url plus manual <a> rendering',
            'reason' => 'Hyper `getLink()` returns rendered markup; Craft LinkData should be rendered explicitly in Twig.',
        ],
        [
            'pattern' => 'getHtml(',
            'replacement' => 'manual rendering',
            'reason' => 'Hyper embed/html helpers do not exist on Craft LinkData.',
        ],
        [
            'pattern' => 'getData(',
            'replacement' => 'manual mapping or backup-only data',
            'reason' => 'Hyper embed/provider payload helpers do not exist on Craft LinkData.',
        ],
        [
            'pattern' => 'getElement(',
            'replacement' => '.element',
            'reason' => 'Craft LinkData exposes relational targets through `.element` instead of `getElement()`.',
        ],
        [
            'pattern' => 'hasElement(',
            'replacement' => 'if link.element',
            'reason' => 'Craft LinkData does not provide `hasElement()`; check `.element` directly.',
        ],
        [
            'pattern' => 'verbb\\hyper\\links\\',
            'replacement' => 'entry, asset, category, email, phone, sms, url',
            'reason' => 'Hyper type checks often use class names; Craft LinkData `.type` uses short handles.',
        ],
        [
            'pattern' => 'verbb\\\\hyper\\\\links\\\\',
            'replacement' => 'entry, asset, category, email, phone, sms, url',
            'reason' => 'Escaped Hyper class-name checks in PHP strings must be rewritten to Craft LinkData short handles.',
        ],
    ];

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

            $settings = array_merge($field->getAttributes(), method_exists($field, 'getSettings') ? $field->getSettings() : []);
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
        $result->mismatchReferences = $this->findMismatchReferences();
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
            '.linkText',
            '.target',
            'getLink(',
            'getHtml(',
            'getData(',
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

    public function findMismatchReferences(): array
    {
        $roots = [
            Craft::getAlias('@root/templates'),
            Craft::getAlias('@root/modules'),
            Craft::getAlias('@root/src'),
            Craft::getAlias('@root/config'),
        ];

        $matches = [];

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
                    foreach (self::MISMATCH_PATTERNS as $mismatch) {
                        if (!str_contains($line, $mismatch['pattern'])) {
                            continue;
                        }

                        $matches[] = [
                            'file' => $fileInfo->getPathname(),
                            'line' => $lineNumber + 1,
                            'pattern' => $mismatch['pattern'],
                            'replacement' => $mismatch['replacement'],
                            'reason' => $mismatch['reason'],
                            'snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $matches;
    }
}
