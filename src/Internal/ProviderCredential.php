<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\AuthenticationError;

/**
 * A token provider, called before each attempt. It may cache the token itself.
 *
 * @internal
 */
final class ProviderCredential implements Credential
{
    /**
     * @param \Closure(): mixed $provider
     */
    public function __construct(
        private readonly \Closure $provider,
    ) {}

    public function token(): string
    {
        try {
            $value = ($this->provider)();
        } catch (\Throwable $error) {
            throw AuthenticationError::noCredential('The token provider failed: ' . $error->getMessage(), $error);
        }
        if (!\is_string($value) || $value === '') {
            throw AuthenticationError::noCredential('The token provider did not return a token.');
        }

        return $value;
    }

    public function refreshable(): bool
    {
        return false;
    }

    public function invalidate(): void {}
}
