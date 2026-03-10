<?php

namespace lm2k\hypertolink;

use Craft;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use lm2k\hypertolink\services\AuditService;
use lm2k\hypertolink\services\ContentMigrationService;
use lm2k\hypertolink\services\FieldMigrationService;
use lm2k\hypertolink\services\MappingStrategyService;
use lm2k\hypertolink\services\ReportService;
use lm2k\hypertolink\services\StateService;

class HyperToLink extends Plugin
{
    public const HANDLE = 'hyper-to-link';

    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;
    public string $schemaVersion = '1.0.0';

    public static self $plugin;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'audit' => AuditService::class,
            'mappingStrategy' => MappingStrategyService::class,
            'fieldMigration' => FieldMigrationService::class,
            'contentMigration' => ContentMigrationService::class,
            'report' => ReportService::class,
            'state' => StateService::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lm2k\\hypertolink\\console\\controllers';
        }
    }

    public function getAudit(): AuditService
    {
        /** @var AuditService */
        return $this->get('audit');
    }

    public function getMappingStrategy(): MappingStrategyService
    {
        /** @var MappingStrategyService */
        return $this->get('mappingStrategy');
    }

    public function getFieldMigration(): FieldMigrationService
    {
        /** @var FieldMigrationService */
        return $this->get('fieldMigration');
    }

    public function getContentMigration(): ContentMigrationService
    {
        /** @var ContentMigrationService */
        return $this->get('contentMigration');
    }

    public function getReport(): ReportService
    {
        /** @var ReportService */
        return $this->get('report');
    }

    public function getState(): StateService
    {
        /** @var StateService */
        return $this->get('state');
    }
}
