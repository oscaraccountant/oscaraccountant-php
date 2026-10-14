<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every conformance case of the source repository. Outside it, the cases are not there and the test skips.
 */
final class ConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $cases = Runner::loadCases();
        if ($cases === []) {
            yield 'no cases' => [[]];
        }
        foreach ($cases as $case) {
            yield Value::string($case['id']) => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        if ($case === []) {
            self::markTestSkipped('No conformance cases: outside the source repository.');
        }
        $reason = Value::map($case['skip'] ?? [])[Runner::RUNNER] ?? null;
        if (\is_string($reason)) {
            self::markTestSkipped($reason);
        }
        Runner::play($case);
        $this->addToAssertionCount(1);
    }
}
