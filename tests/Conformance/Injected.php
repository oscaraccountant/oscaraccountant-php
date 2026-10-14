<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

/**
 * The hooks of a case. The sleep does not wait: it records its argument and moves the clock on.
 */
final class Injected
{
    public float $now;

    public float $jitterValue;

    /**
     * @var list<string>
     */
    public array $uuids;

    /**
     * @var list<float>
     */
    public array $sleeps = [];

    /**
     * @param array<string, mixed> $inject
     */
    public function __construct(array $inject)
    {
        $this->now = self::parseTime(Value::string($inject['now'] ?? Runner::DEFAULT_NOW));
        $this->jitterValue = Value::number($inject['jitter'] ?? 0);
        $this->uuids = array_map(Value::string(...), Value::list($inject['uuids'] ?? []));
    }

    public static function parseTime(string $value): float
    {
        return (float) (new \DateTimeImmutable($value))->format('U.u');
    }

    public function clock(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }

    public function jitter(): float
    {
        return $this->jitterValue;
    }

    public function uuid(): string
    {
        if ($this->uuids === []) {
            throw new CaseMismatch('The client asked the UUID source for one key too many.');
        }

        return array_shift($this->uuids);
    }
}
