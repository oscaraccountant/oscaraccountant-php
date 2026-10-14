<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

/**
 * What the call gave: a result of a kind, or an error.
 */
final class Outcome
{
    public string $kind = '';

    public mixed $value = null;

    public ?\Throwable $error = null;
}
