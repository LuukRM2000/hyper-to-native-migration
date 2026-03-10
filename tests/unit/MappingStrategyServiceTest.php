<?php

namespace lm2k\hypertolink\tests\unit;

use Codeception\Test\Unit;
use lm2k\hypertolink\models\MappingDecision;
use lm2k\hypertolink\services\MappingStrategyService;

class MappingStrategyServiceTest extends Unit
{
    public function testSupportedSingleLinkMapping(): void
    {
        $service = new MappingStrategyService();
        $decision = $service->decide(['showText' => true], ['entry', 'url'], false, []);

        self::assertSame(MappingDecision::STATUS_SUPPORTED, $decision->status);
        self::assertSame(['entry', 'url'], $decision->craftLinkTypes);
        self::assertContains('label', $decision->advancedFields);
    }

    public function testMultiLinkIsUnsupported(): void
    {
        $service = new MappingStrategyService();
        $decision = $service->decide([], ['entry'], true, []);

        self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status);
        self::assertNotEmpty($decision->unsupportedReasons);
    }

    public function testCustomFieldsDowngradeToPartial(): void
    {
        $service = new MappingStrategyService();
        $decision = $service->decide([], ['url'], false, [['uid' => 'x']]);

        self::assertSame(MappingDecision::STATUS_PARTIAL, $decision->status);
        self::assertContains('customFields', $decision->lossyAttributes);
    }
}
