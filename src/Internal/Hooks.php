<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

/**
 * The clock, the sleep, the jitter, the UUID source and the random bytes of a client. The tests replace them.
 *
 * @internal
 */
final class Hooks
{
    /**
     * @var \Closure(): float the current time, a UNIX time in seconds
     */
    public readonly \Closure $clock;

    /**
     * @var \Closure(float): void the wait before a retry, in seconds
     */
    public readonly \Closure $sleep;

    /**
     * @var \Closure(): float a number in [0, 1) for the backoff
     */
    public readonly \Closure $jitter;

    /**
     * @var \Closure(): string a UUID version 4, in lower case, for `Idempotency-Key`
     */
    public readonly \Closure $uuid;

    /**
     * @var \Closure(int): string random bytes, for the boundary of a multipart body
     */
    public readonly \Closure $randomBytes;

    /**
     * @param (\Closure(): float)|null $clock
     * @param (\Closure(float): void)|null $sleep
     * @param (\Closure(): float)|null $jitter
     * @param (\Closure(): string)|null $uuid
     * @param (\Closure(int): string)|null $randomBytes
     */
    public function __construct(
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
        ?\Closure $jitter = null,
        ?\Closure $uuid = null,
        ?\Closure $randomBytes = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep(max(0, (int) round($seconds * 1_000_000)));
        };
        $this->jitter = $jitter ?? static fn(): float => random_int(0, \PHP_INT_MAX - 1) / \PHP_INT_MAX;
        $this->uuid = $uuid ?? self::uuid4(...);
        $this->randomBytes = $randomBytes ?? static fn(int $length): string => random_bytes(max(1, $length));
    }

    /**
     * A UUID version 4 from `random_bytes`.
     */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
