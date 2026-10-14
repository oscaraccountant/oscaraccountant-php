<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\ConfigurationError;
use OscarAccountant\Oscar;

/**
 * The base URL of the API: the option, then `OSCAR_BASE_URL`, then the default, normalized.
 *
 * @internal
 */
final class BaseUrl
{
    /**
     * The hosts that may use `http`: the loopback hosts. A host that ends in `.personal` may too.
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private const OVERLAY_SUFFIX = '.personal';

    /**
     * The option, else `OSCAR_BASE_URL`, else `Oscar::DEFAULT_BASE_URL`, normalized. An empty string counts as
     * not set.
     *
     * @throws ConfigurationError
     */
    public static function resolve(?string $option): string
    {
        $value = $option !== null && $option !== '' ? $option : (Environment::get(Environment::BASE_URL) ?? Oscar::DEFAULT_BASE_URL);

        return self::normalize($value);
    }

    /**
     * The base URL without a trailing `/`, with the scheme and the host in lower case.
     *
     * @throws ConfigurationError for a URL that is not `https`, or `http` on a loopback or overlay host
     */
    public static function normalize(string $value): string
    {
        $parts = parse_url(trim($value));
        if ($parts === false) {
            throw new ConfigurationError(\sprintf('The base URL "%s" is not a valid URL.', $value));
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationError(\sprintf('The base URL "%s" must be a scheme, a host, a port and a path only.', $value));
        }
        $local = \in_array($host, self::LOOPBACK_HOSTS, true) || str_ends_with($host, self::OVERLAY_SUFFIX);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new ConfigurationError(\sprintf(
                'The base URL "%s" must use https (http only for localhost, 127.0.0.1, [::1] or *.personal).',
                $value,
            ));
        }
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $scheme . '://' . $authority . rtrim($parts['path'] ?? '', '/');
    }
}
