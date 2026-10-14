<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

/**
 * The environment variables of the client, read once when the client is made.
 *
 * @internal
 */
final class Environment
{
    public const API_KEY = 'OSCAR_API_KEY';
    public const BASE_URL = 'OSCAR_BASE_URL';
    public const PROFILE = 'OSCAR_PROFILE';
    public const USE_CLI_LOGIN = 'OSCAR_USE_CLI_LOGIN';

    /**
     * The value of a variable, or null when it is not set or empty: an empty string counts as not set.
     */
    public static function get(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
