<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\ArrayWorker;
use WorkerFramework\Runtime\Tests\Fixtures\FailingWorker;
use WorkerFramework\Runtime\Tests\Fixtures\InjectedWorker;
use WorkerFramework\Runtime\Tests\Fixtures\InterfaceWorker;
use WorkerFramework\Runtime\Tests\Fixtures\InvokableWorker;
use WorkerFramework\Runtime\Tests\Fixtures\LegacyWorker;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\Fixtures\ThrowingWorker;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

final class HandlerModeTest extends RuntimeTestCase
{
    public function testAWorkerImplementingTheInterfaceIsCalledWithTheContext(): void
    {
        $result = $this->runWorker(['handler', InterfaceWorker::class], ['WORKER_JOB_ID' => 'job-7']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['handle'], Recorder::events());
        self::assertSame('job-7', Recorder::detail('handle'));
    }

    public function testTheProofOfConceptsPerformMethodStillWorks(): void
    {
        $result = $this->runWorker([LegacyWorker::class]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['perform'], Recorder::events());
    }

    public function testPayloadFieldsAreMappedOntoTypedArguments(): void
    {
        $this->runWorker(
            ['handler', InvokableWorker::class],
            ['WORKER_PAYLOAD' => '{"company_id": "42", "reportType": "weekly"}'],
        );

        self::assertSame(
            ['companyId' => 42, 'reportType' => 'weekly', 'dryRun' => false],
            Recorder::detail('invoke'),
            'snake_case keys map to camelCase parameters, strings are cast, defaults fill the rest',
        );
    }

    public function testAnArrayParameterReceivesTheWholePayload(): void
    {
        $this->runWorker(['handler', ArrayWorker::class], ['WORKER_PAYLOAD' => '{"a": 1, "b": 2}']);

        self::assertSame(['a' => 1, 'b' => 2], Recorder::detail('array'));
    }

    public function testAnExplicitMethodCanBeNamed(): void
    {
        $result = $this->runWorker(['handler', LegacyWorker::class . '::perform']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['perform'], Recorder::events());
    }

    public function testAnIntegerReturnBecomesTheExitCode(): void
    {
        self::assertSame(17, $this->runWorker(['handler', FailingWorker::class])->exitCode);
    }

    public function testAThrownExceptionIsLoggedAndFailsTheContainer(): void
    {
        $result = $this->runWorker(['handler', ThrowingWorker::class]);

        self::assertSame(ExitCode::WORKER_ERROR, $result->exitCode);
        self::assertStringContainsString('worker exploded', $result->log);
        self::assertStringContainsString('RuntimeException', $result->log);
    }

    public function testAWorkerWithDependenciesComesFromTheContainer(): void
    {
        $result = $this->runWorker(['handler', InjectedWorker::class], [
            'WORKER_BOOTSTRAP' => $this->bootstrap('container.php'),
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame('mysql://reports', Recorder::detail('injected'));
    }

    public function testAWorkerWithDependenciesAndNoContainerExplainsItself(): void
    {
        $result = $this->runWorker(['handler', InjectedWorker::class]);

        self::assertSame(ExitCode::HANDLER_NOT_FOUND, $result->exitCode);
        self::assertStringContainsString('constructor argument', $result->log);
        self::assertStringContainsString('services.yaml', $result->log);
    }

    public function testAnUnknownHandlerIsReported(): void
    {
        $result = $this->runWorker(['handler', 'App\\Nope']);

        self::assertSame(ExitCode::HANDLER_NOT_FOUND, $result->exitCode);
        self::assertStringContainsString('could not be resolved', $result->log);
    }

    public function testAClassWithNoRecognisedMethodIsReported(): void
    {
        $result = $this->runWorker(['handler', \stdClass::class]);

        self::assertSame(ExitCode::HANDLER_NOT_FOUND, $result->exitCode);
        self::assertStringContainsString('exposes none of the expected methods', $result->log);
    }

    private function bootstrap(string $name): string
    {
        return \dirname(__DIR__) . '/Fixtures/bootstrap/' . $name;
    }
}
