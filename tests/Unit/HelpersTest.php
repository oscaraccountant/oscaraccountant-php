<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use OscarAccountant\Errors\ConfigurationError;
use OscarAccountant\Errors\UsageError;
use OscarAccountant\Generated\Models\CommandOutcome;
use OscarAccountant\Generated\Models\Organization;
use OscarAccountant\Generated\Models\PaginatedOrganizationList;
use OscarAccountant\Generated\Runtime\Request;
use OscarAccountant\Internal\BaseUrl;
use OscarAccountant\Internal\Hooks;
use OscarAccountant\Internal\Json;
use OscarAccountant\Internal\RequestBuilder;
use OscarAccountant\Internal\Retry;
use OscarAccountant\Internal\UserAgent;
use OscarAccountant\Paginator;
use OscarAccountant\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    use Environment;

    /**
     * @return iterable<array{string, string}>
     */
    public static function goodUrls(): iterable
    {
        yield ['https://API.Example.com/', 'https://api.example.com'];
        yield ['  https://example.com/base/ ', 'https://example.com/base'];
        yield ['http://localhost:3500', 'http://localhost:3500'];
        yield ['http://127.0.0.1', 'http://127.0.0.1'];
        yield ['http://[::1]:8300/', 'http://[::1]:8300'];
        yield ['HTTP://devbox.personal:8080', 'http://devbox.personal:8080'];
    }

    #[DataProvider('goodUrls')]
    public function testNormalize(string $value, string $normalized): void
    {
        self::assertSame($normalized, BaseUrl::normalize($value));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function badUrls(): iterable
    {
        yield ['http://example.com'];
        yield ['ftp://localhost'];
        yield ['example.com'];
        yield ['https://user:pass@example.com'];
        yield ['https://example.com/?a=1'];
        yield ['https://example.com/#x'];
        yield ['https://example.com:99999'];
        yield ['http:///nohost'];
    }

    #[DataProvider('badUrls')]
    public function testNormalizeRefuses(string $value): void
    {
        $this->expectException(ConfigurationError::class);
        BaseUrl::normalize($value);
    }

    public function testResolveOrder(): void
    {
        $this->clearOscarEnvironment();
        self::assertSame('https://oscaraccountant.com', BaseUrl::resolve(null));
        putenv('OSCAR_BASE_URL=https://env.example.com');
        self::assertSame('https://env.example.com', BaseUrl::resolve(''));
        self::assertSame('https://option.example.com', BaseUrl::resolve('https://option.example.com'));
    }

    public function testUserAgent(): void
    {
        $version = Version::VERSION;
        self::assertSame("oscaraccountant-php/{$version} (linux; x86_64; php/8.2.1)", UserAgent::build('Linux', 'amd64', '8.2.1'));
        self::assertSame("oscaraccountant-php/{$version} (macos; arm64; php/8.5.0)", UserAgent::build('Darwin', 'arm64', '8.5.0'));
        self::assertSame("oscaraccountant-php/{$version} (windows; riscv64; php/8.3.0)", UserAgent::build('Windows', 'RISCV64', '8.3.0'));
        self::assertSame("oscaraccountant-php/{$version} (unknown; unknown; php/8.3.0)", UserAgent::build('BSD', '', '8.3.0'));
        self::assertSame("oscaraccountant-php/{$version} (unknown; unknown; php/8.3.0)", UserAgent::build('BSD', '(;)', '8.3.0'));
        self::assertStringStartsWith('oscaraccountant-php/', UserAgent::build());
    }

    public function testRetry(): void
    {
        self::assertTrue(Retry::isRetryableStatus(503, null));
        self::assertTrue(Retry::isRetryableStatus(409, 'idempotency_in_progress'));
        self::assertFalse(Retry::isRetryableStatus(409, 'conflict'));
        self::assertFalse(Retry::isRetryableStatus(400, null));
        self::assertSame(0.5, Retry::backoff(1, 0.0));
        self::assertSame(8.0 * 0.75, Retry::backoff(10, 1.0));
        $now = (float) (new \DateTimeImmutable('2026-09-27T10:00:00Z'))->format('U');
        self::assertNull(Retry::retryAfter(null, $now));
        self::assertNull(Retry::retryAfter('soon', $now));
        self::assertSame(3.0, Retry::retryAfter('3', $now));
        self::assertSame(7.0, Retry::retryAfter('Sun, 27 Sep 2026 10:00:07 GMT', $now));
        self::assertSame(7.0, Retry::retryAfter('Sunday, 27-Sep-26 10:00:07 GMT', $now));
        self::assertSame(7.0, Retry::retryAfter('Sun Sep 27 10:00:07 2026', $now));
        self::assertSame(0.0, Retry::retryAfter('Sun, 27 Sep 2026 09:00:00 GMT', $now));
        self::assertNull(Retry::delay(1, '120', $now, 0.0));
        self::assertSame(1.0, Retry::delay(2, null, $now, 0.0));
    }

    public function testPathAndQuery(): void
    {
        self::assertSame('/api/a%2Fb%20c~._-/x', RequestBuilder::encodePath('/api/{id}/x', ['id' => 'a/b c~._-']));
        foreach (['', '.', '..'] as $value) {
            try {
                RequestBuilder::encodePath('/api/{id}/', ['id' => $value]);
                self::fail('expected UsageError');
            } catch (UsageError) {
                $this->addToAssertionCount(1);
            }
        }
        $date = new \DateTimeImmutable('2026-09-27T23:30:00+03:00');
        self::assertSame(
            '?a=true&b=false&c=5&d=0.1&e=2026-09-27&f=x%20y&g=100000000000000000000',
            RequestBuilder::query(['a' => true, 'b' => false, 'c' => 5, 'd' => 0.1, 'e' => $date, 'f' => 'x y', 'g' => 1e20, 'h' => null]),
        );
        self::assertSame('', RequestBuilder::query(['h' => null]));
        self::assertSame('owner', RequestBuilder::wireValue(\OscarAccountant\Generated\Enums\RoleEnum::Owner));
        $this->expectException(UsageError::class);
        RequestBuilder::wireValue([1]);
    }

    public function testJson(): void
    {
        self::assertSame('{}', Json::encodeBody([]));
        self::assertSame('{"a":"é/","b":1.0}', Json::encodeBody(['a' => 'é/', 'b' => 1.0]));
        self::assertEquals((object) ['n' => '123456789012345678901234567890'], Json::decode('{"n":123456789012345678901234567890}'));
        $this->expectException(\JsonException::class);
        Json::decode('{');
    }

    public function testDecodedModelKeepsTheShapeOfUntypedJson(): void
    {
        $text = '{"command":"c","command_id":"01J","commit_seq":1,"replayed":false,"result":{},"future":{},"keys":{"0":"a","1":"b"},"list":[]}';
        $outcome = CommandOutcome::fromJson(Json::decode($text));
        self::assertEquals(new \stdClass(), $outcome->result);
        self::assertSame($text, Json::encodeBody($outcome));
        self::assertSame(['a', 'b'], array_values((array) $outcome->extra()['keys']));
        // A key that starts with NUL cannot be a property: the document still decodes, with arrays.
        $nul = CommandOutcome::fromJson(Json::decode('{"command":"c","command_id":"01J","commit_seq":1,"replayed":false,"result":{"\\u0000x":1}}'));
        self::assertSame(["\0x" => 1], $nul->result);
    }

    public function testHooksDefaults(): void
    {
        $hooks = new Hooks();
        self::assertEqualsWithDelta(microtime(true), ($hooks->clock)(), 5.0);
        $jitter = ($hooks->jitter)();
        self::assertGreaterThanOrEqual(0.0, $jitter);
        self::assertLessThan(1.0, $jitter);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', ($hooks->uuid)());
        self::assertSame(16, \strlen(($hooks->randomBytes)(16)));
        $start = microtime(true);
        ($hooks->sleep)(0.01);
        self::assertGreaterThanOrEqual(0.005, microtime(true) - $start);
    }

    public function testPaginatorIsLazyAndFollowsTheCursor(): void
    {
        $organization = static fn(string $id, string $name): array => [
            'id' => $id,
            'name' => $name,
            'role' => 'owner',
            'permissions' => [],
            'companies' => [],
            'created_at' => '2026-09-27T10:00:00Z',
            'updated_at' => '2026-09-27T10:00:00Z',
        ];
        $pages = [
            ['items' => [$organization('o1', 'One')], 'next_cursor' => 'c2'],
            ['items' => [$organization('o2', 'Two')], 'next_cursor' => null],
        ];
        $log = new class {
            /**
             * @var list<Request>
             */
            public array $requests = [];
        };
        $fetch = static function (Request $request) use (&$pages, $log): PaginatedOrganizationList {
            $log->requests[] = $request;
            $page = array_shift($pages) ?? throw new \LogicException('no page left');

            return PaginatedOrganizationList::fromArray($page);
        };
        $request = new Request('organizations_list', 'GET', '/api/organizations/', [], ['limit' => 1, 'cursor' => null], null, false, 'json');
        /** @var Paginator<PaginatedOrganizationList, Organization> $paginator */
        $paginator = new Paginator($fetch, $request);
        $iterator = $paginator->getIterator();
        self::assertSame(0, \count($log->requests));
        $names = [];
        foreach ($iterator as $organization) {
            $names[] = $organization->name;
        }
        self::assertSame(['One', 'Two'], $names);
        [$first, $second] = $log->requests;
        self::assertNull($first->query['cursor']);
        self::assertSame('c2', $second->query['cursor']);
        self::assertSame(1, $second->query['limit']);
    }
}
