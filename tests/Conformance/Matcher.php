<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

/**
 * The comparison of JSON values of the cases.
 *
 * Numbers compare by value (`1` equals `1.0`), a boolean never equals a number. An empty PHP array is an
 * empty object or an empty array, because `json_decode` gives both as `[]`. With `$subset`, the actual object
 * may have more keys, and an expected null may be absent (the match rule of a result).
 */
final class Matcher
{
    /**
     * Where `$actual` differs from `$expected`, or null.
     */
    public static function mismatch(mixed $expected, mixed $actual, string $where = '$', bool $subset = false): ?string
    {
        if (\is_array($expected) && $expected === []) {
            return $actual === [] ? null : self::differ($where, $expected, $actual);
        }
        if (\is_array($expected) && array_is_list($expected)) {
            return self::listMismatch($expected, $actual, $where, $subset);
        }
        if (\is_array($expected)) {
            return self::objectMismatch($expected, $actual, $where, $subset);
        }
        if (\is_bool($expected) || \is_bool($actual)) {
            return $expected === $actual ? null : self::differ($where, $expected, $actual);
        }
        if ((\is_int($expected) || \is_float($expected)) && (\is_int($actual) || \is_float($actual))) {
            return (float) $expected === (float) $actual ? null : self::differ($where, $expected, $actual);
        }

        return $expected === $actual ? null : self::differ($where, $expected, $actual);
    }

    /**
     * @param list<mixed> $expected
     */
    private static function listMismatch(array $expected, mixed $actual, string $where, bool $subset): ?string
    {
        if (!\is_array($actual) || !array_is_list($actual) || \count($actual) !== \count($expected)) {
            return self::differ($where, $expected, $actual);
        }
        foreach ($expected as $index => $want) {
            $found = self::mismatch($want, $actual[$index], $where . '[' . $index . ']', $subset);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $expected
     */
    private static function objectMismatch(array $expected, mixed $actual, string $where, bool $subset): ?string
    {
        if (!\is_array($actual) || ($actual !== [] && array_is_list($actual))) {
            return self::differ($where, $expected, $actual);
        }
        if (!$subset) {
            $wantKeys = array_map(strval(...), array_keys($expected));
            $haveKeys = array_map(strval(...), array_keys($actual));
            sort($wantKeys);
            sort($haveKeys);
            if ($wantKeys !== $haveKeys) {
                return \sprintf('%s: expected the keys %s, got %s', $where, json_encode($wantKeys), json_encode($haveKeys));
            }
        }
        foreach ($expected as $key => $value) {
            if (!\array_key_exists($key, $actual)) {
                if ($subset && $value === null) {
                    continue;
                }

                return $where . '.' . $key . ': missing';
            }
            $found = self::mismatch($value, $actual[$key], $where . '.' . $key, $subset);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function differ(string $where, mixed $expected, mixed $actual): string
    {
        return \sprintf('%s: expected %s, got %s', $where, json_encode($expected), json_encode($actual));
    }
}
