<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Integration;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * The mock server of the source repository, started once per test run as a real process with `uv run --locked`.
 *
 * Without `uv` or without the mock server (the public repository), the suite skips. With
 * `OSCAR_MOCK_SERVER_REQUIRED=1` (CI), it fails instead.
 */
final class MockServer
{
    private const READY_PREFIX = 'OSCAR_MOCK_SERVER_READY ';

    /**
     * The first start may create the environment of the mock server.
     */
    private const START_TIMEOUT = 180.0;

    private static ?self $instance = null;

    private function __construct(
        public readonly string $url,
        public readonly string $apiKey,
        public readonly string $companyId,
        public readonly string $organizationId,
    ) {}

    public static function get(): self
    {
        return self::$instance ??= self::start();
    }

    private static function start(): self
    {
        $directory = \dirname(__DIR__, 3) . '/mock-server';
        $uv = self::which('uv');
        if ($uv === null || !is_file($directory . '/pyproject.toml')) {
            $message = 'The mock server is not available: it needs uv and the mock server of the source repository.';
            if (getenv('OSCAR_MOCK_SERVER_REQUIRED') === '1') {
                Assert::fail($message);
            }
            TestCase::markTestSkipped($message);
        }
        // The environment of this SDK is not the one of the mock server.
        $environment = getenv();
        unset($environment['VIRTUAL_ENV'], $environment['UV_PROJECT_ENVIRONMENT']);
        $log = tempnam(sys_get_temp_dir(), 'oscar-mock-server');
        $command = [$uv, 'run', '--quiet', '--locked', '--project', $directory, 'oscar-mock-server', '--port', '0'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', (string) $log, 'w']], $pipes, $directory, $environment);
        if (!\is_resource($process)) {
            Assert::fail('The mock server did not start.');
        }
        $deadline = microtime(true) + self::START_TIMEOUT;
        $info = null;
        while ($info === null) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0 || stream_select($read, $write, $except, (int) ceil($left)) === 0) {
                break;
            }
            $line = fgets($pipes[1]);
            if ($line === false) {
                break;
            }
            if (str_starts_with($line, self::READY_PREFIX)) {
                $info = json_decode(substr($line, \strlen(self::READY_PREFIX)), true, 512, \JSON_THROW_ON_ERROR);
            }
        }
        if (!\is_array($info)) {
            proc_terminate($process);
            Assert::fail("The mock server did not start:\n" . file_get_contents((string) $log));
        }
        $server = new self(self::text($info['url'] ?? null), self::text($info['api_key'] ?? null), self::text($info['company_id'] ?? null), self::text($info['organization_id'] ?? null));
        register_shutdown_function(static function () use ($server, $process, $pipes): void {
            try {
                $server->control('POST', 'shutdown');
            } catch (\Throwable) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            proc_close($process);
        });

        return $server;
    }

    /**
     * A call of the control API under `/_mock/`.
     *
     * @param non-empty-string $method
     */
    public function control(string $method, string $path, mixed $body = null): mixed
    {
        $handle = curl_init($this->url . '/_mock/' . $path);
        curl_setopt_array($handle, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 10,
            \CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, json_encode($body, \JSON_THROW_ON_ERROR));
        }
        $answer = curl_exec($handle);
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        if (!\is_string($answer) || $status >= 400) {
            throw new \RuntimeException(\sprintf('The control call %s %s failed with %d.', $method, $path, $status));
        }

        return $answer === '' ? null : json_decode($answer, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * A fresh state, before each test.
     */
    public function reset(): void
    {
        $this->control('POST', 'reset');
    }

    /**
     * Scripted answers: each wins over the fixtures for the requests it matches.
     */
    public function script(mixed ...$responses): void
    {
        $this->control('POST', 'responses', ['responses' => $responses]);
    }

    /**
     * The request log of the API: every request under `/api/`.
     *
     * @return list<array<string, mixed>>
     */
    public function apiRequests(): array
    {
        $log = $this->control('GET', 'requests');
        $entries = [];
        foreach (\is_array($log) && \is_array($log['requests'] ?? null) ? $log['requests'] : [] as $entry) {
            if (\is_array($entry) && \is_string($entry['path'] ?? null) && str_starts_with($entry['path'], '/api/')) {
                /** @var array<string, mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function text(mixed $value): string
    {
        if (!\is_string($value)) {
            Assert::fail('The mock server gave an answer the test cannot read.');
        }

        return $value;
    }

    private static function which(string $name): ?string
    {
        $path = getenv('PATH');
        foreach (explode(\PATH_SEPARATOR, \is_string($path) ? $path : '') as $directory) {
            $candidate = $directory . \DIRECTORY_SEPARATOR . $name;
            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
