<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Application;

use WorkerFramework\Runtime\Exception\BootstrapException;

/**
 * The pieces of a user application the runtime managed to get hold of.
 *
 * Nothing in here is type-hinted against Symfony. A worker image is expected
 * to run plain PHP jobs as happily as a full Symfony app, and the runtime
 * ships no Composer dependencies of its own, so every framework class is
 * looked up by name at the point of use.
 */
final class ApplicationContext
{
    /**
     * @param array<string, object> $receivers  Messenger receivers keyed by transport name
     * @param array<string, mixed>  $extra      anything else a bootstrap file returned
     */
    public function __construct(
        private readonly ?object $kernel = null,
        private readonly ?object $container = null,
        private readonly ?object $console = null,
        private readonly ?object $bus = null,
        private readonly array $receivers = [],
        private readonly ?object $serializer = null,
        private readonly ?object $eventDispatcher = null,
        private readonly array $extra = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function kernel(): ?object
    {
        return $this->kernel;
    }

    public function container(): ?object
    {
        return $this->container;
    }

    public function console(): ?object
    {
        return $this->console;
    }

    public function bus(): ?object
    {
        return $this->bus;
    }

    /**
     * @return array<string, object>
     */
    public function receivers(): array
    {
        return $this->receivers;
    }

    public function serializer(): ?object
    {
        return $this->serializer;
    }

    public function eventDispatcher(): ?object
    {
        return $this->eventDispatcher;
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return $this->extra;
    }

    public function withConsole(?object $console): self
    {
        return new self(
            $this->kernel,
            $this->container,
            $console,
            $this->bus,
            $this->receivers,
            $this->serializer,
            $this->eventDispatcher,
            $this->extra,
        );
    }

    public function hasService(string $id): bool
    {
        return null !== $this->container
            && method_exists($this->container, 'has')
            && $this->container->has($id);
    }

    /**
     * Fetch the first of $ids the container can actually hand out.
     *
     * Symfony makes most services private, so `has()` returning false is the
     * normal case rather than an error; callers decide whether a miss is fatal.
     *
     * @param list<string> $ids
     */
    public function findService(array $ids): ?object
    {
        foreach ($ids as $id) {
            if ($this->hasService($id)) {
                /** @var object $service */
                $service = $this->container->get($id);

                return $service;
            }
        }

        return null;
    }

    /**
     * @param list<string> $ids
     */
    public function requireService(array $ids, string $what, string $hint): object
    {
        return $this->findService($ids) ?? throw new BootstrapException(sprintf(
            "Could not resolve %s from the application container (tried: %s).\n%s",
            $what,
            implode(', ', $ids),
            $hint,
        ));
    }
}
