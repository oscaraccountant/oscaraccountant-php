<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit\Support;

/**
 * The PHP built-in server with `router.php`, started once for the transport tests.
 */
final class TestServer
{
    private static ?self $instance = null;

    private function __construct(
        public readonly string $url,
    ) {}

    private static ?self $proxy = null;

    public static function get(): self
    {
        if (self::$instance === null) {
            $port = self::freePort();
            self::$instance = new self(self::start([\PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'], $port));
        }

        return self::$instance;
    }

    /**
     * The fake proxy `connect-proxy.php`, which answers a CONNECT and then closes the tunnel.
     */
    public static function proxy(): self
    {
        if (self::$proxy === null) {
            $port = self::freePort();
            self::$proxy = new self(self::start([\PHP_BINARY, __DIR__ . '/connect-proxy.php', (string) $port], $port));
        }

        return self::$proxy;
    }

    /**
     * Start a process that listens on the port, wait until it answers, and give its URL.
     *
     * @param list<string> $command
     */
    private static function start(array $command, int $port): string
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('The test server did not start.');
        }
        $deadline = microtime(true) + 10;
        while (true) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (\is_resource($socket)) {
                fclose($socket);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                throw new \RuntimeException('The test server did not answer.');
            }
            usleep(50_000);
        }
        register_shutdown_function(static function () use ($process): void {
            proc_terminate($process);
            proc_close($process);
        });

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * A port that no process listens on.
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('No free port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
