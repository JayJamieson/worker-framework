<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Invoker;

use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\ShutdownAware;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Handler\ArgumentResolver;
use WorkerFramework\Runtime\Handler\HandlerResolver;
use WorkerFramework\Runtime\Handler\ResolvedHandler;
use WorkerFramework\Runtime\Log\Logger;

/**
 * Runs a single handler to completion - the framework's original job, now with
 * dependency injection, argument resolution and cooperative shutdown.
 */
final class HandlerInvoker implements Invoker
{
    private ?ResolvedHandler $handler = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly ApplicationContext $application,
        private readonly Logger $logger,
    ) {
    }

    public function describe(): string
    {
        return sprintf('handler %s', $this->handler()->describe());
    }

    public function invoke(Context $context): int
    {
        $handler = $this->handler();

        if ($handler->target instanceof ShutdownAware) {
            $worker = $handler->target;
            $context->onShutdown(static fn (int $signal) => $worker->onShutdown($context, $signal));
        }

        $arguments = (new ArgumentResolver($this->application))->resolve($handler->reflect(), $context);

        $this->logger->info('Invoking handler', [
            'handler' => $handler->describe(),
            'arguments' => \count($arguments),
        ]);

        return $this->toExitCode($handler->call($arguments));
    }

    /**
     * A handler may report failure by returning an int exit code or false;
     * anything else - including no return value at all - counts as success.
     */
    private function toExitCode(mixed $result): int
    {
        return match (true) {
            \is_int($result) => $result,
            false === $result => ExitCode::WORKER_ERROR,
            default => ExitCode::SUCCESS,
        };
    }

    private function handler(): ResolvedHandler
    {
        return $this->handler ??= (new HandlerResolver($this->application))->resolve($this->config->handler);
    }
}
