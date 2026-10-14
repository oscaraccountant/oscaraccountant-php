<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

/**
 * When a request is sent again, and after how long.
 *
 * @internal
 */
final class Retry
{
    /**
     * The statuses that are retried. A 409 is retried only with the code `idempotency_in_progress`.
     */
    public const STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * A `Retry-After` above this ends the retries, in seconds.
     */
    public const MAX_RETRY_AFTER = 60.0;

    /**
     * The first delay, and the longest one, before the jitter, in seconds.
     */
    public const BASE_DELAY = 0.5;

    public const MAX_DELAY = 8.0;

    /**
     * A status that is worth a retry of a request that may be retried.
     */
    public static function isRetryableStatus(int $status, ?string $code): bool
    {
        return \in_array($status, self::STATUSES, true) || ($status === 409 && $code === 'idempotency_in_progress');
    }

    /**
     * The delay before retry `$retry` (1, 2, ...), with `$jitter` in [0, 1).
     */
    public static function backoff(int $retry, float $jitter): float
    {
        return min(self::MAX_DELAY, self::BASE_DELAY * 2.0 ** ($retry - 1)) * (1 - 0.25 * $jitter);
    }

    /**
     * The delay that `Retry-After` asks for, in seconds, or null when there is no usable header.
     *
     * Whole seconds, or an HTTP date against `$now` (a UNIX time). A date in the past is 0.
     */
    public static function retryAfter(?string $value, float $now): ?float
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $utc = new \DateTimeZone('UTC');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $when = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
            if ($when !== false) {
                return max(0.0, (float) $when->format('U.u') - $now);
            }
        }

        return null;
    }

    /**
     * The delay before retry `$retry`, or null when `Retry-After` asks for more than 60 seconds.
     */
    public static function delay(int $retry, ?string $retryAfter, float $now, float $jitter): ?float
    {
        $asked = self::retryAfter($retryAfter, $now);
        if ($asked === null) {
            return self::backoff($retry, $jitter);
        }

        return $asked > self::MAX_RETRY_AFTER ? null : $asked;
    }
}
