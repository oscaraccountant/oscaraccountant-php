<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

/**
 * Typed reads of the decoded JSON of a case. A value of another type fails the case.
 */
final class Value
{
    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new CaseMismatch('The case has no object where it needs one.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new CaseMismatch('The case has no array where it needs one.');
        }

        return array_values($value);
    }

    public static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new CaseMismatch('The case has no string where it needs one.');
        }

        return $value;
    }

    public static function int(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new CaseMismatch('The case has no integer where it needs one.');
        }

        return $value;
    }

    public static function number(mixed $value): float
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new CaseMismatch('The case has no number where it needs one.');
        }

        return (float) $value;
    }
}
