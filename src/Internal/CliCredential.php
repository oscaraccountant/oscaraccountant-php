<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

/**
 * The stored login of the CLI (step 3).
 *
 * @internal
 */
final class CliCredential implements Credential
{
    public function __construct(
        private readonly CliLogin $login,
    ) {}

    public function token(): string
    {
        return $this->login->token();
    }

    public function refreshable(): bool
    {
        return true;
    }

    public function invalidate(): void
    {
        $this->login->invalidate();
    }
}
