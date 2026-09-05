<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Fixtures;

/**
 * Records what fixtures did, so tests can assert on behaviour that happens
 * inside a worker run rather than in the test's own call stack.
 */
final class Recorder
{
    /** @var list<array{0: string, 1: mixed}> */
    private static array $entries = [];

    public static function record(string $event, mixed $detail = null): void
    {
        self::$entries[] = [$event, $detail];
    }

    public static function reset(): void
    {
        self::$entries = [];
    }

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return array_column(self::$entries, 0);
    }

    public static function detail(string $event): mixed
    {
        foreach (self::$entries as [$name, $detail]) {
            if ($name === $event) {
                return $detail;
            }
        }

        return null;
    }

    /**
     * Every detail recorded for $event, in order.
     *
     * @return list<mixed>
     */
    public static function details(string $event): array
    {
        $details = [];

        foreach (self::$entries as [$name, $detail]) {
            if ($name === $event) {
                $details[] = $detail;
            }
        }

        return $details;
    }

    public static function count(string $event): int
    {
        return \count(array_filter(self::events(), static fn (string $name): bool => $name === $event));
    }
}
