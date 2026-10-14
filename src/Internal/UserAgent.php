<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Version;

/**
 * The `User-Agent` of the SDK: `oscaraccountant-php/<version> (<os>; <arch>; php/<version>)`.
 *
 * @internal
 */
final class UserAgent
{
    private const SYSTEMS = ['linux' => 'linux', 'darwin' => 'macos', 'windows' => 'windows'];

    private const MACHINES = ['x86_64' => 'x86_64', 'amd64' => 'x86_64', 'arm64' => 'arm64', 'aarch64' => 'arm64'];

    /**
     * The arguments are for the tests; the defaults read the running PHP.
     */
    public static function build(?string $system = null, ?string $machine = null, ?string $phpVersion = null): string
    {
        $system ??= \PHP_OS_FAMILY;
        $machine ??= self::machine();
        $phpVersion ??= \PHP_VERSION;
        $os = self::SYSTEMS[strtolower($system)] ?? 'unknown';
        $arch = self::MACHINES[strtolower($machine)] ?? (strtolower($machine) !== '' ? strtolower($machine) : 'unknown');
        $arch = (string) preg_replace('/[^a-z0-9_.\-]/', '', $arch);
        $arch = $arch !== '' ? $arch : 'unknown';

        return \sprintf('oscaraccountant-php/%s (%s; %s; php/%s)', Version::VERSION, $os, $arch, $phpVersion);
    }

    /**
     * The machine of `php_uname`, or an empty string when the host disables the function.
     */
    private static function machine(): string
    {
        return \function_exists('php_uname') ? php_uname('m') : '';
    }
}
