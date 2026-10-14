<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\ConfigurationError;

/**
 * The credential of a client, in this order:
 *
 * 1. An explicit `apiKey` or `tokenProvider`.
 * 2. `OSCAR_API_KEY`.
 * 3. The stored login of the CLI, only with `credential: 'cli'` or `OSCAR_USE_CLI_LOGIN=1`.
 * 4. Otherwise `AuthenticationError`, raised before the first request.
 *
 * @internal
 */
final class Credentials
{
    public const MISSING_MESSAGE = 'No credential for the Oscar API. Pass apiKey or tokenProvider, set OSCAR_API_KEY, '
        . "or run `oscar login` and pass credential: 'cli' (or set OSCAR_USE_CLI_LOGIN=1).";

    /**
     * The first source of the order that is set. An empty string counts as not set.
     *
     * @param (callable(): string)|null $tokenProvider
     *
     * @throws ConfigurationError
     */
    public static function resolve(
        ?string $apiKey,
        ?callable $tokenProvider,
        ?string $credential,
        ?string $profile,
        string $baseUrl,
        Hooks $hooks,
    ): Credential {
        $apiKey = $apiKey !== '' ? $apiKey : null;
        if ($apiKey !== null && $tokenProvider !== null) {
            throw new ConfigurationError('Pass apiKey or tokenProvider, not both.');
        }
        if ($credential !== null && $credential !== 'cli') {
            throw new ConfigurationError(\sprintf("credential must be 'cli' or null, not \"%s\".", $credential));
        }
        if ($apiKey !== null) {
            return new StaticCredential($apiKey);
        }
        if ($tokenProvider !== null) {
            return new ProviderCredential($tokenProvider(...));
        }
        $environment = Environment::get(Environment::API_KEY);
        if ($environment !== null) {
            return new StaticCredential($environment);
        }
        if ($credential === 'cli' || Environment::get(Environment::USE_CLI_LOGIN) === '1') {
            $profile = $profile !== null && $profile !== '' ? $profile : Environment::get(Environment::PROFILE);

            return new CliCredential(new CliLogin($baseUrl, $profile, $hooks->clock));
        }

        return new MissingCredential();
    }
}
