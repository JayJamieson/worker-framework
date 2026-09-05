<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkerFramework\Runtime\Limits;

final class LimitsTest extends TestCase
{
    public function testUnboundedLimitsProduceOnlyTheSleepOption(): void
    {
        self::assertSame(['--sleep=1'], (new Limits())->toConsoleOptions());
    }

    public function testEveryConfiguredLimitBecomesAConsumeOption(): void
    {
        $limits = Limits::fromEnvironment([
            'WORKER_MESSAGE_LIMIT' => '100',
            'WORKER_TIME_LIMIT' => '3600',
            'WORKER_MEMORY_LIMIT' => '128M',
            'WORKER_FAILURE_LIMIT' => '3',
            'WORKER_SLEEP' => '500000',
        ]);

        self::assertSame([
            '--limit=100',
            '--time-limit=3600',
            '--memory-limit=128M',
            '--failure-limit=3',
            '--sleep=0.5',
        ], $limits->toConsoleOptions());
    }
}
