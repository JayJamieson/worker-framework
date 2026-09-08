<?php

/**
 * Dependency-free PSR-4 autoloader for the runtime itself.
 *
 * The runtime deliberately avoids Composer for its own classes: the worker's
 * `vendor/` directory belongs to the user's application, and dropping runtime
 * dependencies into it is the fastest way to create version conflicts with
 * Symfony. Everything the runtime needs from the framework is resolved at call
 * time via `class_exists()` checks instead.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WorkerFramework\\Runtime\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
