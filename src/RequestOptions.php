<?php

declare(strict_types=1);

namespace OscarAccountant;

/**
 * The options of one call. Every one that is null takes the value of the client.
 *
 * ```php
 * $oscar->invoices->cancel($companyId, $invoiceId, $body, options: new RequestOptions(idempotencyKey: $key));
 * ```
 */
final readonly class RequestOptions
{
    /**
     * @param string|null $idempotencyKey the `Idempotency-Key` of the call; the client makes one when it is null
     * @param float|null $timeout the timeout of each attempt, in seconds
     * @param int|null $maxRetries the retries after the first attempt; 0 turns them off
     * @param array<string, string> $headers headers for this call; they cannot replace `Authorization` or
     *                                       `Idempotency-Key`
     */
    public function __construct(
        public ?string $idempotencyKey = null,
        public ?float $timeout = null,
        public ?int $maxRetries = null,
        public array $headers = [],
    ) {}
}
