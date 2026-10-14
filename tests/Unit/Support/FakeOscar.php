<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit\Support;

/**
 * A fake `oscar` CLI in a directory of its own, for the tests of step 3.
 *
 * The script reads its answers from files next to it, and logs its arguments, one call per line.
 */
final class FakeOscar
{
    public readonly string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/oscar-php-fake-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $script = <<<'SH'
            #!/bin/sh
            here=$(dirname "$0")
            echo "$*" >> "$here/calls.log"
            if [ -S /dev/stdout ]; then echo socket >> "$here/stdout.log"; else echo other >> "$here/stdout.log"; fi
            if [ "$1" = "--version" ]; then
              cat "$here/version.txt"
              exit 0
            fi
            if [ -f "$here/sleep.txt" ]; then
              sleep "$(cat "$here/sleep.txt")"
            fi
            cat "$here/stdout.txt"
            cat "$here/stderr.txt" >&2
            exit "$(cat "$here/exit.txt")"
            SH;
        file_put_contents($this->directory . '/oscar', $script . "\n");
        chmod($this->directory . '/oscar', 0o755);
        $this->version('oscar-cli 0.1.0');
        $this->answer(0, '', '');
    }

    public function version(string $line): void
    {
        file_put_contents($this->directory . '/version.txt', $line . "\n");
    }

    public function answer(int $exit, string $stdout, string $stderr = ''): void
    {
        file_put_contents($this->directory . '/exit.txt', (string) $exit);
        file_put_contents($this->directory . '/stdout.txt', $stdout);
        file_put_contents($this->directory . '/stderr.txt', $stderr);
    }

    /**
     * Wait this many seconds before each answer but the version.
     */
    public function sleep(float $seconds): void
    {
        file_put_contents($this->directory . '/sleep.txt', (string) $seconds);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function token(array $fields): void
    {
        $this->answer(0, (string) json_encode($fields));
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        $log = $this->directory . '/calls.log';
        if (!is_file($log)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn(string $line): bool => $line !== ''));
    }

    /**
     * The kind of the standard output of each call: `socket` or `other`.
     *
     * @return list<string>
     */
    public function stdoutKinds(): array
    {
        $log = $this->directory . '/stdout.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn(string $line): bool => $line !== '')) : [];
    }

    public function remove(): void
    {
        $files = glob($this->directory . '/*');
        foreach (\is_array($files) ? $files : [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
