<?php

namespace lm2k\hypertolink\controllers;

use Craft;
use craft\web\Controller;
use lm2k\hypertolink\HyperToLink;
use yii\web\Response;

class WizardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $plugin = HyperToLink::$plugin;
        $audit = $plugin->getAudit()->buildAudit();
        $plugin->getState()->syncAuditedFields($audit);

        return $this->renderTemplate(HyperToLink::HANDLE . '/index', [
            'plugin' => $plugin,
            'audit' => $audit,
            'statuses' => $plugin->getState()->workflowStatuses($audit),
            'canWrite' => $plugin->getLicense()->canWrite(),
            'edition' => $plugin->getLicense()->currentEdition(),
            'licenseIssues' => $plugin->getLicense()->issues(),
            'writeBlockReason' => $plugin->getLicense()->writeBlockReason(),
        ]);
    }

    public function actionPrepareFields(): Response
    {
        $this->requirePostRequest();
        $plugin = HyperToLink::$plugin;

        try {
            $plugin->getLicense()->requireWriteAccess(false);
            $audit = $plugin->getAudit()->buildAudit();
            $plugin->getState()->syncAuditedFields($audit);
            $result = $plugin->getFieldMigration()->migrate($audit, ['dryRun' => false, 'force' => true]);

            if ($result->hasErrors()) {
                Craft::$app->getSession()->setFlash('error', 'Prepare completed with errors. Check the CLI reports for details.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Native Link fields prepared successfully.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', $e->getMessage());
        }

        return $this->redirect(HyperToLink::HANDLE);
    }

    public function actionMigrateContent(): Response
    {
        $this->requirePostRequest();
        $plugin = HyperToLink::$plugin;

        try {
            $plugin->getLicense()->requireWriteAccess(false);
            $audit = $plugin->getAudit()->buildAudit();
            $plugin->getState()->syncAuditedFields($audit);
            $result = $plugin->getContentMigration()->migrate($audit, [
                'dryRun' => false,
                'force' => true,
                'createBackup' => true,
                'batchSize' => 100,
            ]);

            if ($result->hasErrors()) {
                Craft::$app->getSession()->setFlash('error', 'Content migration completed with errors. Check the CLI reports for details.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Content migration completed successfully.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', $e->getMessage());
        }

        return $this->redirect(HyperToLink::HANDLE);
    }

    public function actionFinalize(): Response
    {
        $this->requirePostRequest();
        $plugin = HyperToLink::$plugin;

        try {
            $plugin->getLicense()->requireWriteAccess(false);
            $audit = $plugin->getAudit()->buildAudit();
            $plugin->getState()->syncAuditedFields($audit);
            $result = $plugin->getCutover()->finalize($audit, ['dryRun' => false, 'force' => true]);

            if ($result->hasErrors()) {
                Craft::$app->getSession()->setFlash('error', 'Finalize completed with errors. Check the CLI reports for details.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Field layout cutover completed successfully.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', $e->getMessage());
        }

        return $this->redirect(HyperToLink::HANDLE);
    }
}
