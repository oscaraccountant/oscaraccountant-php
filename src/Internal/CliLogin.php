<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\AuthenticationError;
use OscarAccountant\Errors\ConfigurationError;

/**
 * Step 3 of the credential order: the token of the stored login of the `oscar` CLI, for one base URL and
 * profile, cached in memory.
 *
 * It runs only when the caller asks for it. The token is never logged, never put in an error message and
 * never written to disk. The CLI runs through `proc_open` with an array command, so never through a shell.
 *
 * @internal
 */
final class CliLogin
{
    /**
     * The first line of `oscar --version`.
     */
    public const VERSION_PATTERN = '/^oscar-cli (\d+\.\d+\.\d+)(\S*)$/';

    /**
     * The timeout of `oscar auth token`, which may refresh the token first, and of `oscar --version`.
     */
    public const TIMEOUT = 30.0;

    /**
     * A token is fetched again this many seconds before it expires.
     */
    public const EXPIRY_MARGIN = 60.0;

    /**
     * The exit code of the CLI for "authentication needed".
     */
    public const EXIT_AUTH = 3;

    /**
     * The extensions of `PATHEXT` that the SDK runs on Windows.
     */
    private const WINDOWS_EXTENSIONS = ['.exe', '.cmd'];

    private const DEFAULT_PATHEXT = '.COM;.EXE;.BAT;.CMD';

    /**
     * The CLI executables whose version was checked in this process.
     *
     * @var array<string, true>
     */
    private static array $checked = [];

    /**
     * @var array{token: string, expires: float|null}|null
     */
    private ?array $cached = null;

    /**
     * @param \Closure(): float $clock
     * @param (\Closure(): (string|null))|null $path the value of `PATH`, for the tests
     * @param float $timeout the timeout of one run of the CLI in seconds, for the tests
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $profile,
        private readonly \Closure $clock,
        private readonly ?\Closure $path = null,
        private readonly float $timeout = self::TIMEOUT,
    ) {}

    /**
     * A token for the base URL. The cache holds until 60 seconds before it expires.
     *
     * @throws AuthenticationError
     */
    public function token(): string
    {
        $cached = $this->cached;
        if ($cached === null || ($cached['expires'] !== null && ($this->clock)() >= $cached['expires'] - self::EXPIRY_MARGIN)) {
            $cached = $this->fetch();
            $this->cached = $cached;
        }

        return $cached['token'];
    }

    /**
     * Forget the cached token, after a 401 of the API.
     */
    public function invalidate(): void
    {
        $this->cached = null;
    }

    /**
     * The absolute path of `oscar` in the directories of `PATH`, or null.
     *
     * Only absolute directories count: never the current directory, also not on Windows, and never a relative
     * entry, which resolves against it. On Windows, `oscar.exe` or `oscar.cmd` in the order of `PATHEXT`.
     */
    public static function find(?string $path, ?bool $windows = null, ?string $pathext = null): ?string
    {
        $windows ??= \PHP_OS_FAMILY === 'Windows';
        $names = ['oscar'];
        if ($windows) {
            $names = [];
            foreach (explode(';', $pathext ?? self::DEFAULT_PATHEXT) as $extension) {
                $extension = strtolower($extension);
                if (\in_array($extension, self::WINDOWS_EXTENSIONS, true)) {
                    $names[] = 'oscar' . $extension;
                }
            }
        }
        foreach (explode($windows ? ';' : ':', $path ?? '') as $directory) {
            if ($directory === '' || !self::isAbsolute($directory, $windows)) {
                continue;
            }
            foreach ($names as $name) {
                $candidate = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate) && ($windows || is_executable($candidate))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Forget which executables were checked, for the tests.
     */
    public static function forgetChecks(): void
    {
        self::$checked = [];
    }

    /**
     * Read the JSON object of `oscar auth token --json`. The message of an error never holds the output.
     *
     * @return array{token: string, expires: float|null, base_url: string}
     *
     * @throws AuthenticationError
     */
    public static function parse(string $stdout): array
    {
        $unreadable = 'The oscar CLI gave an answer the SDK cannot read.';
        try {
            $answer = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw AuthenticationError::noCredential($unreadable, $error);
        }
        if (!\is_array($answer)) {
            throw AuthenticationError::noCredential($unreadable);
        }
        $token = $answer['token'] ?? null;
        $baseUrl = $answer['base_url'] ?? null;
        $expiresAt = $answer['expires_at'] ?? null;
        if (!\is_string($token) || $token === '' || !\is_string($baseUrl) || ($expiresAt !== null && !\is_string($expiresAt))) {
            throw AuthenticationError::noCredential($unreadable);
        }
        $expires = null;
        if ($expiresAt !== null) {
            try {
                $expires = (float) (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->format('U.u');
            } catch (\Exception $error) {
                throw AuthenticationError::noCredential($unreadable, $error);
            }
        }

        return ['token' => $token, 'expires' => $expires, 'base_url' => $baseUrl];
    }

    /**
     * @return array{token: string, expires: float|null}
     */
    private function fetch(): array
    {
        if (!\function_exists('proc_open')) {
            throw AuthenticationError::noCredential(
                'The CLI login needs proc_open, which this PHP disables (disable_functions). Set OSCAR_API_KEY instead.',
            );
        }
        $path = $this->path !== null ? ($this->path)() : Environment::get('PATH');
        $executable = self::find($path);
        if ($executable === null) {
            throw AuthenticationError::noCredential(
                'The oscar CLI is not installed: no `oscar` on PATH. Install it (npx @oscaraccountant/cli), or set OSCAR_API_KEY.',
            );
        }
        $this->checkVersion($executable);
        $arguments = [$executable, 'auth', 'token', '--json', '--base-url', $this->baseUrl];
        if ($this->profile !== null) {
            array_push($arguments, '--profile', $this->profile);
        }
        [$code, $stdout, $stderr] = $this->run($arguments);
        if ($code !== 0) {
            $first = strtok(trim($stderr), "\r\n");
            $reason = \is_string($first) ? $first : 'no message';
            $hint = $code === self::EXIT_AUTH ? ' Run `oscar login` first.' : '';
            throw AuthenticationError::noCredential(\sprintf('`oscar auth token` failed with exit code %d: %s.%s', $code, $reason, $hint));
        }
        $answer = self::parse($stdout);
        try {
            $answeredFor = BaseUrl::normalize($answer['base_url']);
        } catch (ConfigurationError) {
            $answeredFor = $answer['base_url'];
        }
        if ($answeredFor !== $this->baseUrl) {
            throw AuthenticationError::noCredential(\sprintf(
                'The oscar CLI answered for %s, not for %s. The token is not used.',
                $answeredFor,
                $this->baseUrl,
            ));
        }

        return ['token' => $answer['token'], 'expires' => $answer['expires']];
    }

    /**
     * Require `oscar-cli <semver>` on the first line of `oscar --version`, once per process and executable.
     */
    private function checkVersion(string $executable): void
    {
        if (isset(self::$checked[$executable])) {
            return;
        }
        [$code, $stdout] = $this->run([$executable, '--version']);
        $first = strtok($stdout, "\r\n");
        if ($code !== 0 || !\is_string($first) || preg_match(self::VERSION_PATTERN, trim($first)) !== 1) {
            throw AuthenticationError::noCredential(\sprintf('`%s` is not the oscar CLI.', $executable));
        }
        self::$checked[$executable] = true;
    }

    /**
     * Run the CLI with no shell and the environment of the caller, within the timeout.
     *
     * The output comes through sockets, not pipes: on Windows, `stream_select()` fails on the pipes of
     * `proc_open()` but works on its sockets (PHP 8.0 and later).
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} the exit code, the standard output and the standard error
     */
    private function run(array $arguments): array
    {
        $pipes = [];
        $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['socket'], 2 => ['socket']], $pipes);
        if (!\is_resource($process)) {
            throw AuthenticationError::noCredential(\sprintf('`%s` could not be run.', $arguments[0]));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = ['', ''];
        $deadline = microtime(true) + $this->timeout;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($process, 9);
                foreach ($open as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
                throw $this->timedOut($arguments[0]);
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1_000_000)) === false) {
                break;
            }
            foreach ($open as $index => $pipe) {
                $chunk = fread($pipe, 65536);
                if (\is_string($chunk) && $chunk !== '') {
                    $output[$index - 1] .= $chunk;
                } elseif (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$index]);
                }
            }
        }
        $code = proc_close($process);

        return [$code, $output[0], $output[1]];
    }

    private function timedOut(string $executable): AuthenticationError
    {
        return AuthenticationError::noCredential(\sprintf('`%s` did not answer within %s seconds.', $executable, $this->timeout));
    }

    private static function isAbsolute(string $directory, bool $windows): bool
    {
        if ($windows) {
            return preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/]{2})#', $directory) === 1;
        }

        return str_starts_with($directory, '/');
    }
}
