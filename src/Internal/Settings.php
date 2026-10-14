<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

/**
 * The settings of a client that every call reads.
 *
 * @internal
 */
final readonly class Settings
{
    /**
     * @param array<string, string> $defaultHeaders the headers of every call
     */
    public function __construct(
        public string $baseUrl,
        public float $timeout,
        public int $maxRetries,
        public string $userAgent,
        public array $defaultHeaders,
        public Hooks $hooks,
    ) {}
}
