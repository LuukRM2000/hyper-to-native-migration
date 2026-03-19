<?php

namespace lm2k\hypertolink\controllers;

use Craft;
use craft\web\Controller;
use lm2k\hypertolink\HyperToLink;
use yii\web\Response;

class MigrateController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionRun(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAdmin();

        @set_time_limit(0);

        $request = Craft::$app->getRequest();
        $formValues = [
            'workflowAction' => (string)$request->getBodyParam('workflowAction', 'all'),
            'field' => trim((string)$request->getBodyParam('field', '')) ?: null,
            'dryRun' => $this->bodyBool('dryRun', true),
            'createBackup' => $this->bodyBool('createBackup', true),
            'batchSize' => max(1, (int)$request->getBodyParam('batchSize', 100)),
            'applyProjectConfig' => $this->bodyBool('applyProjectConfig', true),
            'verbose' => $this->bodyBool('verbose', false),
        ];

        $formValues['force'] = !$formValues['dryRun'];
        $result = HyperToLink::$plugin->getRunner()->run($formValues['workflowAction'], $formValues);

        return $this->renderTemplate(HyperToLink::HANDLE . '/index', [
            'formValues' => $formValues,
            'result' => $result,
        ]);
    }

    private function bodyBool(string $name, bool $default): bool
    {
        $value = Craft::$app->getRequest()->getBodyParam($name);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
