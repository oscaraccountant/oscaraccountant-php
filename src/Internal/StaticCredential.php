<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

/**
 * An API key, given in code or by `OSCAR_API_KEY`.
 *
 * @internal
 */
final class StaticCredential implements Credential
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $value,
    ) {}

    public function token(): string
    {
        return $this->value;
    }

    public function refreshable(): bool
    {
        return false;
    }

    public function invalidate(): void {}
}
