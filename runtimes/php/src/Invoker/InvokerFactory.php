<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Invoker;

use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Mode;

/**
 * Picks the invoker for the configured mode.
 */
final class InvokerFactory
{
    public static function create(Configuration $config, ApplicationContext $application, Logger $logger): Invoker
    {
        return match ($config->mode) {
            Mode::Handler => new HandlerInvoker($config, $application, $logger),
            Mode::Console => new ConsoleInvoker($config, $application, $logger),
            Mode::Consume => new ConsumeInvoker($config, $application, $logger),
            Mode::Message => new MessageInvoker($config, $application, $logger),
        };
    }
}
