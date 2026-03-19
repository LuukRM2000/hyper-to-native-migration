<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use lm2k\hypertolink\HyperToLink;

class LicenseService extends Component
{
    public function currentEdition(): string
    {
        return HyperToLink::$plugin->edition ?: HyperToLink::EDITION_LITE;
    }

    public function isProEdition(): bool
    {
        return $this->currentEdition() === HyperToLink::EDITION_PRO;
    }

    public function issues(): array
    {
        return Craft::$app->getPlugins()->getLicenseIssues(HyperToLink::HANDLE);
    }

    public function canWrite(): bool
    {
        return $this->isProEdition() && $this->issues() === [];
    }

    public function writeBlockReason(): ?string
    {
        if (!$this->isProEdition()) {
            return 'Write operations require the Pro edition. Audit, mismatch scans, dry runs, and status remain available in Lite.';
        }

        $issues = $this->issues();
        if ($issues === []) {
            return null;
        }

        return sprintf(
            'Write operations are blocked until the plugin license issues are resolved: %s.',
            implode(', ', $issues)
        );
    }

    public function requireWriteAccess(bool $dryRun = false): void
    {
        if ($dryRun) {
            return;
        }

        $reason = $this->writeBlockReason();
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }
    }
}
