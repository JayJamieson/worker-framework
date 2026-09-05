<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Log\LogLevel;

final class LoggerTest extends TestCase
{
    public function testRecordsBelowTheMinimumLevelAreDropped(): void
    {
        [$logger, $read] = $this->logger(LogLevel::Warning);

        $logger->info('quiet');
        $logger->error('loud');

        self::assertStringNotContainsString('quiet', $read());
        self::assertStringContainsString('loud', $read());
    }

    public function testJsonFormatEmitsOneObjectPerLine(): void
    {
        [$logger, $read] = $this->logger(LogLevel::Info, 'json');

        $logger->info('started', ['transport' => 'async']);
        $logger->error('failed', ['attempt' => 3]);

        $lines = array_filter(explode("\n", $read()));

        self::assertCount(2, $lines);

        $first = json_decode((string) reset($lines), true);

        self::assertSame('info', $first['level']);
        self::assertSame('started', $first['message']);
        self::assertSame('async', $first['transport']);
    }

    public function testContextIsCarriedIntoEveryRecord(): void
    {
        [$logger, $read] = $this->logger();

        $logger->withContext(['job_id' => 'abc123'])->info('working');

        self::assertStringContainsString('job_id=abc123', $read());
    }

    public function testValuesContainingSpacesAreQuoted(): void
    {
        [$logger, $read] = $this->logger();

        $logger->info('running', ['command' => 'app:import --force']);

        self::assertStringContainsString('command="app:import --force"', $read());
    }

    public function testExceptionsAreLoggedWithOriginAndTrace(): void
    {
        [$logger, $read] = $this->logger();

        $logger->exception(new \RuntimeException('boom'), 'Handler failed');

        $output = $read();

        self::assertStringContainsString('Handler failed', $output);
        self::assertStringContainsString('exception=RuntimeException', $output);
        self::assertStringContainsString('error=boom', $output);
        self::assertStringContainsString(basename(__FILE__), $output);
    }

    /**
     * @return array{0: Logger, 1: callable(): string}
     */
    private function logger(LogLevel $level = LogLevel::Debug, string $format = 'text'): array
    {
        $stream = fopen('php://memory', 'w+b');

        return [
            new Logger($level, $format, [], $stream),
            static function () use ($stream): string {
                rewind($stream);

                return (string) stream_get_contents($stream);
            },
        ];
    }
}
