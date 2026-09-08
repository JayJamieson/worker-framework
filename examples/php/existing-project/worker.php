<?php

/**
 * The bridge between an existing application and the worker runtime.
 *
 * The runtime looks for this file at $WORKER_ROOT/worker.php and uses whatever
 * it returns instead of trying to discover a framework. For an app with no
 * Symfony kernel, this is the whole integration: boot the app the way it
 * normally boots, register the few services the runtime needs to reach, and
 * hand back a container.
 *
 * With this in place the runtime can construct both:
 *
 *   - the handler       CMD ["handler", "Acme\\Worker\\SendInvoicesJob::perform"]
 *   - the job reporter  WORKER_JOB_REPORTER=Acme\Worker\JobStatusReporter
 *
 * with their real dependencies, because it fetches them from here by class
 * name rather than calling `new`.
 */

declare(strict_types=1);

use Acme\Worker\Container;
use Acme\Worker\InvoiceRepository;
use Acme\Worker\JobStatusReporter;
use Acme\Worker\SendInvoicesJob;

require __DIR__ . '/vendor/autoload.php';

// However this application already establishes its connections, config and
// legacy includes - call it here, unchanged.
// require __DIR__ . '/legacy/bootstrap.php';

$container = new Container();

$container->set(PDO::class, static fn (): PDO => new PDO(
    getenv('DATABASE_DSN') ?: 'mysql:host=db;dbname=acme;charset=utf8mb4',
    getenv('DATABASE_USER') ?: 'acme',
    getenv('DATABASE_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
));

$container->set(InvoiceRepository::class, static fn (Container $c): InvoiceRepository
    => new InvoiceRepository($c->get(PDO::class)));

$container->set(SendInvoicesJob::class, static fn (Container $c): SendInvoicesJob
    => new SendInvoicesJob($c->get(InvoiceRepository::class)));

// The runtime resolves WORKER_JOB_REPORTER through this container too, which
// is how a reporter gets constructor dependencies without any autowiring.
$container->set(JobStatusReporter::class, static fn (Container $c): JobStatusReporter
    => new JobStatusReporter($c->get(PDO::class)));

return ['container' => $container];
