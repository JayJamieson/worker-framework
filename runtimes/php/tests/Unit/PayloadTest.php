<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\Payload;

final class PayloadTest extends TestCase
{
    public function testJsonObjectsAreDecoded(): void
    {
        $payload = Payload::fromString('{"companyId": 42, "nested": {"a": 1}}');

        self::assertSame(42, $payload->get('companyId'));
        self::assertSame(['a' => 1], $payload->get('nested'));
        self::assertTrue($payload->has('companyId'));
        self::assertFalse($payload->has('missing'));
    }

    public function testNonJsonInputIsKeptVerbatim(): void
    {
        $payload = Payload::fromString('a plain string');

        self::assertSame([], $payload->all());
        self::assertSame('a plain string', $payload->raw());
        self::assertFalse($payload->isEmpty());
    }

    public function testJsonScalarsDoNotBecomeFields(): void
    {
        // "42" is valid JSON but there is nothing sensible to key it by.
        self::assertSame([], Payload::fromString('42')->all());
        self::assertSame('42', Payload::fromString('42')->raw());
    }

    public function testWhitespaceOnlyPayloadsAreEmpty(): void
    {
        self::assertTrue(Payload::fromString("  \n ")->isEmpty());
        self::assertTrue(Payload::empty()->isEmpty());
    }

    public function testTheEnvironmentVariableWinsOverTheFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'payload');
        file_put_contents($file, '{"from": "file"}');

        try {
            $payload = Payload::discover([
                'WORKER_PAYLOAD' => '{"from": "env"}',
                'WORKER_PAYLOAD_FILE' => $file,
            ]);

            self::assertSame('env', $payload->get('from'));
        } finally {
            unlink($file);
        }
    }

    public function testAPayloadFileIsRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'payload');
        file_put_contents($file, '{"from": "file"}');

        try {
            self::assertSame('file', Payload::discover(['WORKER_PAYLOAD_FILE' => $file])->get('from'));
        } finally {
            unlink($file);
        }
    }

    public function testAMissingPayloadFileIsReported(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WORKER_PAYLOAD_FILE');

        Payload::discover(['WORKER_PAYLOAD_FILE' => '/no/such/payload.json']);
    }

    public function testARedirectedStdinIsReadWithoutBeingAskedTo(): void
    {
        // `cat job.json | docker run -i worker` should just work.
        self::assertSame('stdin', Payload::discover([], $this->stream('{"from": "stdin"}'))->get('from'));
    }

    public function testStdinIsNotReadWhenExplicitlyDisabled(): void
    {
        $payload = Payload::discover(['WORKER_PAYLOAD_STDIN' => '0'], $this->stream('{"from": "stdin"}'));

        self::assertTrue($payload->isEmpty());
    }

    public function testAClosedStdinIsNotAnError(): void
    {
        self::assertTrue(Payload::discover([], null)->isEmpty());
    }

    public function testACharacterDeviceIsLeftAloneSoAContainerNeverWaits(): void
    {
        // What a plain `docker run` (no -i) attaches to stdin.
        $devNull = fopen('/dev/null', 'rb');

        try {
            self::assertTrue(Payload::discover([], $devNull)->isEmpty());
        } finally {
            fclose($devNull);
        }
    }

    /**
     * A temporary file, which fstat reports as a regular file - the same class
     * of stream as `worker < job.json`.
     *
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = tmpfile();
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
