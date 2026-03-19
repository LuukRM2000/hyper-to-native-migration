<?php

namespace lm2k\hypertolink\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use lm2k\hypertolink\HyperToLink;
use yii\console\ExitCode;

class MigrateController extends Controller
{
    public ?string $field = null;
    public bool $dryRun = false;
    public bool $force = false;
    public bool $verbose = false;
    public bool $createBackup = false;
    public int $batchSize = 100;
    public bool $applyProjectConfig = true;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'field';
        $options[] = 'dryRun';
        $options[] = 'force';
        $options[] = 'verbose';
        $options[] = 'createBackup';
        $options[] = 'batchSize';
        $options[] = 'applyProjectConfig';

        return $options;
    }

    public function optionAliases(): array
    {
        return [
            'f' => 'field',
            'n' => 'dryRun',
            'b' => 'createBackup',
            'v' => 'verbose',
            'p' => 'applyProjectConfig',
        ];
    }

    public function actionAudit(): int
    {
        return $this->emitResult(HyperToLink::$plugin->getRunner()->run('audit', $this->optionsPayload()));
    }

    public function actionFields(): int
    {
        return $this->emitResult(HyperToLink::$plugin->getRunner()->run('fields', $this->optionsPayload()));
    }

    public function actionContent(): int
    {
        return $this->emitResult(HyperToLink::$plugin->getRunner()->run('content', $this->optionsPayload()));
    }

    public function actionAll(): int
    {
        return $this->emitResult(HyperToLink::$plugin->getRunner()->run('all', $this->optionsPayload()));
    }

    public function actionRollbackInfo(): int
    {
        return $this->emitResult(HyperToLink::$plugin->getRunner()->run('rollback-info', $this->optionsPayload()));
    }

    public function actionMismatches(): int
    {
        return $this->emitResult(HyperToLink::$plugin->getRunner()->run('mismatches', $this->optionsPayload()));
    }

    private function optionsPayload(): array
    {
        return [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'verbose' => $this->verbose,
            'createBackup' => $this->createBackup,
            'batchSize' => $this->batchSize,
            'applyProjectConfig' => $this->applyProjectConfig,
        ];
    }

    private function emitResult(array $result): int
    {
        foreach ($result['messages'] ?? [] as $message) {
            $text = ($message['text'] ?? '') . "\n";

            if (($message['type'] ?? 'info') === 'error') {
                $this->stderr($text, Console::FG_RED);
                continue;
            }

            $color = match ($message['type'] ?? 'info') {
                'success' => Console::FG_GREEN,
                'warning' => Console::FG_YELLOW,
                default => null,
            };

            $this->stdout($text, $color);
        }

        return (int)($result['exitCode'] ?? ExitCode::UNSPECIFIED_ERROR);
    }
}
