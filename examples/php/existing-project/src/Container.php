<?php

declare(strict_types=1);

namespace Acme\Worker;

use RuntimeException;

/**
 * A container in thirty lines.
 *
 * The runtime does not require psr/container or Symfony DI - it duck-types
 * anything with `has()` and `get()`. That is deliberate: an application with
 * its own service locator, a registry, or nothing at all can satisfy this
 * without adopting a DI package.
 *
 * Services are shared: `get()` builds once and reuses, so the handler and the
 * job reporter get the same PDO handle rather than opening two connections.
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param callable(self): object $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }

        return $this->instances[$id] ??= ($this->factories[$id])($this);
    }
}
