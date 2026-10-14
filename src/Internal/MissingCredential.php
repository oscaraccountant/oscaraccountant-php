<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\AuthenticationError;

/**
 * No credential: every call raises `AuthenticationError` and sends nothing.
 *
 * @internal
 */
final class MissingCredential implements Credential
{
    public function token(): string
    {
        throw AuthenticationError::noCredential(Credentials::MISSING_MESSAGE);
    }

    public function refreshable(): bool
    {
        return false;
    }

    public function invalidate(): void {}
}
