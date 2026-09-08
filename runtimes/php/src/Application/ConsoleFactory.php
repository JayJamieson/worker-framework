<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Application;

use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Console\Application as ConsoleApplication;
use WorkerFramework\Runtime\Exception\BootstrapException;

/**
 * Produces the Console Application that console and consume modes run against.
 *
 * A bootstrap file may hand one over directly, which is how a project using
 * standalone Symfony components (no FrameworkBundle) plugs in. Otherwise the
 * booted Kernel is wrapped in FrameworkBundle's Application, giving the worker
 * the exact same command set as `bin/console`.
 */
final class ConsoleFactory
{
    public static function create(ApplicationContext $application): ConsoleApplication
    {
        $console = $application->console();

        if ($console instanceof ConsoleApplication) {
            return self::configure($console);
        }

        if (null !== $console) {
            throw new BootstrapException(sprintf(
                'The bootstrap file returned a "console" entry of type %s; a %s was expected.',
                $console::class,
                ConsoleApplication::class,
            ));
        }

        if (!class_exists(ConsoleApplication::class)) {
            throw new BootstrapException(
                'symfony/console is not installed in the worker image. Run `composer require symfony/console` '
                . 'in the application, or use handler mode.',
            );
        }

        $kernel = $application->kernel();

        if (null === $kernel) {
            throw new BootstrapException(
                'Console mode needs a Symfony application. Set WORKER_KERNEL_CLASS to your kernel, or return a '
                . 'Console Application from worker.php.',
            );
        }

        if (!class_exists(FrameworkApplication::class)) {
            throw new BootstrapException(
                'A kernel was booted but symfony/framework-bundle is not installed, so its console commands cannot '
                . 'be discovered. Return a configured Console Application from worker.php instead.',
            );
        }

        return self::configure(new FrameworkApplication($kernel));
    }

    /**
     * Exit codes and error reporting belong to the runtime, so the Application
     * is told not to call `exit()` and not to swallow exceptions.
     */
    private static function configure(ConsoleApplication $application): ConsoleApplication
    {
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application;
    }
}
