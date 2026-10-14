<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * 401, or no credential is available: then `$status` is null and nothing was sent.
 */
class AuthenticationError extends ApiError
{
    /**
     * The error of a credential that cannot be found or used, before any request.
     */
    public static function noCredential(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, null, 'no_credential', previous: $previous);
    }
}
