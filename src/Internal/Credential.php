<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\AuthenticationError;

/**
 * The source of the `Authorization: Bearer` value. The client asks it before each attempt.
 *
 * @internal
 */
interface Credential
{
    /**
     * @throws AuthenticationError when no token is available; the call then sends nothing
     */
    public function token(): string;

    /**
     * The source can fetch a new value after a 401 (the CLI login).
     */
    public function refreshable(): bool;

    /**
     * Forget a cached value. Only a refreshable source has one.
     */
    public function invalidate(): void;
}
