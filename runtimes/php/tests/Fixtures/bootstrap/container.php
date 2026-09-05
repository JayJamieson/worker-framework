<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use WorkerFramework\Runtime\Tests\Fixtures\InjectedWorker;
use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;

// Stands in for a compiled Symfony container: services the runtime is meant to
// find are public, everything else is not.
$container = new ContainerBuilder();

$container->register(InjectedWorker::class, InjectedWorker::class)
    ->addArgument('mysql://reports')
    ->setPublic(true);

$container->set('message_bus', MessengerFixture::bus());
$container->set('messenger.default_serializer', MessengerFixture::serializer());

$container->compile();

return ['container' => $container];
