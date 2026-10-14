<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The runner itself: a broken case must fail, or a passing run proves nothing.
 */
final class BrokenCaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, \Closure}>
     */
    public static function broken(): iterable
    {
        $set = static fn(array $path, mixed $value): \Closure => static fn(array $case): array => self::set($case, $path, $value);
        $request = ['exchanges', 0, 'request'];
        yield 'path' => ['operations.invoices.list', $set([...$request, 'path'], '/api/companies/cmp0000001/invoice/')];
        yield 'query' => ['operations.invoices.list', $set([...$request, 'query'], [['limit', '50']])];
        yield 'method' => ['operations.invoices.list', $set([...$request, 'method'], 'POST')];
        yield 'origin' => ['operations.invoices.list', $set(['origin'], 'https://other.example.test')];
        yield 'header value' => ['operations.me.retrieve', $set([...$request, 'headers', 'authorization'], 'Bearer nope')];
        yield 'header absent' => ['operations.me.retrieve', $set([...$request, 'headers', 'authorization'], null)];
        yield 'header pattern' => ['operations.me.retrieve', $set([...$request, 'headers', 'accept'], ['pattern' => '^text/'])];
        yield 'result value' => ['operations.me.retrieve', $set(['expect', 'result', 'value', 'has_totp'], true)];
        yield 'result key' => ['operations.me.retrieve', $set(['expect', 'result', 'value', 'missing'], 1)];
        yield 'result kind' => ['operations.me.retrieve', $set(['expect', 'result', 'kind'], 'none')];
        yield 'sleeps' => ['operations.me.retrieve', $set(['sleeps'], [0.5])];
        yield 'extra exchange' => ['operations.me.retrieve', static function (array $case): array {
            $exchanges = Value::list($case['exchanges']);
            $exchanges[] = $exchanges[0];

            return self::set($case, ['exchanges'], $exchanges);
        }];
        yield 'missing exchange' => ['retries.get-503-then-ok', static function (array $case): array {
            $exchanges = Value::list($case['exchanges']);
            array_pop($exchanges);

            return self::set($case, ['exchanges'], $exchanges);
        }];
        yield 'retry sleep' => ['retries.get-503-then-ok', $set(['sleeps'], [0.6])];
        yield 'json body' => ['operations.invoices.cancel', $set([...$request, 'body', 'value'], ['reason' => 'other'])];
        yield 'no body' => ['operations.invoices.cancel', $set([...$request, 'body'], ['kind' => 'none'])];
        yield 'no uuid' => ['operations.invoices.cancel', $set(['inject', 'uuids'], [])];
        yield 'file hash' => ['operations.files.upload', $set([...$request, 'body', 'parts', 0, 'sha256'], str_repeat('0', 64))];
        yield 'part missing' => ['operations.files.upload', static function (array $case): array {
            $exchange = Value::map(Value::list($case['exchanges'])[0]);
            $parts = Value::list(Value::map(Value::map($exchange['request'])['body'])['parts']);
            array_pop($parts);

            return self::set($case, ['exchanges', 0, 'request', 'body', 'parts'], $parts);
        }];
        yield 'download size' => ['operations.files.download', $set(['expect', 'result', 'size'], 1)];
        yield 'error type' => ['errors.status-404', $set(['expect', 'error', 'type'], 'ConflictError')];
        yield 'error code' => ['errors.status-404', $set(['expect', 'error', 'code'], 'other')];
        yield 'error message' => ['errors.status-404', $set(['expect', 'error', 'message'], 'other')];
        yield 'error not result' => ['errors.status-404', $set(['expect'], ['result' => ['kind' => 'none']])];
        yield 'result not error' => ['operations.me.retrieve', $set(['expect'], ['error' => ['type' => 'NotFoundError']])];
        yield 'timeout' => ['timeouts.call-option', $set([...$request, 'timeout_ms'], 1000)];
    }

    /**
     * A copy of `$data` with the value at `$path` replaced.
     *
     * @param array<mixed> $data
     * @param array<mixed> $path
     *
     * @return array<mixed>
     */
    private static function set(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if (!\is_int($key) && !\is_string($key)) {
            throw new \LogicException('A path is a list of keys.');
        }
        if ($path === []) {
            $data[$key] = $value;

            return $data;
        }
        $child = $data[$key] ?? [];
        $data[$key] = self::set(\is_array($child) ? $child : [], $path, $value);

        return $data;
    }

    #[DataProvider('broken')]
    public function testBrokenCaseFails(string $id, \Closure $change): void
    {
        $case = null;
        foreach (Runner::loadCases() as $candidate) {
            if ($candidate['id'] === $id) {
                $case = $candidate;
            }
        }
        if ($case === null) {
            self::markTestSkipped('No conformance cases: outside the source repository.');
        }
        $this->expectException(\AssertionError::class);
        Runner::play(Value::map($change($case)));
    }

    public function testMatcherRules(): void
    {
        self::assertNull(Matcher::mismatch(1, 1.0));
        self::assertNotNull(Matcher::mismatch(true, 1));
        self::assertNotNull(Matcher::mismatch(1, true));
        self::assertNull(Matcher::mismatch(['a' => null], [], subset: true));
        self::assertNotNull(Matcher::mismatch(['a' => null], []));
        self::assertNull(Matcher::mismatch(['a' => 1], ['a' => 1, 'b' => 2], subset: true));
        self::assertNotNull(Matcher::mismatch(['a' => 1], ['a' => 1, 'b' => 2]));
        self::assertNotNull(Matcher::mismatch([1], [1, 2]));
        self::assertNotNull(Matcher::mismatch(['a' => 1], [1]));
        self::assertNotNull(Matcher::mismatch('1', 1));
        self::assertNull(Matcher::mismatch([], []));
        self::assertSame('bankTransactions', Runner::camel('bank_transactions'));
    }
}
