<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Integration;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use OscarAccountant\Errors\AuthenticationError;
use OscarAccountant\Errors\BadRequestError;
use OscarAccountant\Errors\ConflictError;
use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\InternalServerError;
use OscarAccountant\Errors\NotFoundError;
use OscarAccountant\Errors\PermissionDeniedError;
use OscarAccountant\Errors\RateLimitError;
use OscarAccountant\Errors\TimeoutError;
use OscarAccountant\Generated\Models\CancelInvoiceBodyRequest;
use OscarAccountant\Generated\Models\CreatePartyPayloadRequest;
use OscarAccountant\Generated\Models\IssueInvoiceBodyRequest;
use OscarAccountant\Generated\Models\RecordInvoicePayloadRequest;
use OscarAccountant\Generated\Models\ReviseInvoiceBodyRequest;
use OscarAccountant\Internal\Hooks;
use OscarAccountant\Oscar;
use OscarAccountant\RequestOptions;
use OscarAccountant\Upload;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against the mock server over real HTTP.
 *
 * The mock server checks every request against the OpenAPI document of the public API and refuses what the
 * API would not accept, so a request the SDK builds wrongly fails here even when the unit tests pass.
 */
#[Group('integration')]
final class MockServerTest extends TestCase
{
    private const INVOICE = '01J90000000000000000000116';
    private const PARTY = '01J90000000000000000000179';
    private const DOCUMENT = '01J90000000000000000000096';
    private const BANK_TRANSACTION = '01J90000000000000000000029';
    private const SEEDED_FILE = 'fil0000001';
    private const PDF = "%PDF-1.4\n% integration test\n";
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const BUSY = ['kind' => 'json', 'value' => ['type' => 'about:blank', 'title' => 'Busy', 'status' => 503, 'code' => 'busy']];
    private const REVISION = [
        'invoice_date' => '2026-09-01',
        'due_date' => '2026-09-15',
        'currency' => 'EUR',
        'lines' => [['description' => 'Consulting', 'net_minor' => 10000, 'vat_code' => 'VAT24', 'tax_minor' => 2400]],
    ];

    private MockServer $server;

    /**
     * @var list<float>
     */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->server = MockServer::get();
        $this->server->reset();
        $this->server->control('POST', 'config', ['device_interval' => 0, 'device_enforce_interval' => false]);
    }

    private function oscar(?string $apiKey = null, float $timeout = Oscar::DEFAULT_TIMEOUT, bool $psr18 = false): Oscar
    {
        $hooks = new Hooks(
            sleep: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            jitter: static fn(): float => 0.0,
        );
        $factory = new HttpFactory();

        return new Oscar(
            apiKey: $apiKey ?? $this->server->apiKey,
            baseUrl: $this->server->url,
            timeout: $timeout,
            httpClient: $psr18 ? new Guzzle(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 10]) : null,
            requestFactory: $psr18 ? $factory : null,
            streamFactory: $psr18 ? $factory : null,
            hooks: $hooks,
        );
    }

    private static function record(string $number = 'INV-2026-001'): RecordInvoicePayloadRequest
    {
        return RecordInvoicePayloadRequest::fromArray([
            'direction' => 'purchase',
            'party_id' => self::PARTY,
            'number' => $number,
            'revision' => self::REVISION,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function log(): array
    {
        return $this->server->apiRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private static function headersOf(mixed $entry): array
    {
        $headers = \is_array($entry) ? ($entry['headers'] ?? []) : [];
        $map = [];
        foreach (\is_array($headers) ? $headers : [] as $name => $value) {
            $map[(string) $name] = $value;
        }

        return $map;
    }

    public function testEveryOperation(): void
    {
        foreach ([false, true] as $psr18) {
            $this->server->reset();
            $this->everyOperation($this->oscar(psr18: $psr18));
        }
    }

    private function everyOperation(Oscar $oscar): void
    {
        $company = $this->server->companyId;
        $organization = $this->server->organizationId;

        $count = static fn(iterable $items): int => \count(iterator_to_array($items, false));
        self::assertGreaterThan(0, $count($oscar->accounts->list($company)));
        self::assertNotSame([], $oscar->bankAccounts->list($company, limit: 20)->firstPage()->items);
        self::assertGreaterThan(0, $count($oscar->bankTransactions->list($company, dateFrom: new \DateTimeImmutable('2026-01-01'))));
        $count($oscar->dimensions->list($company));
        self::assertGreaterThan(0, $count($oscar->documents->list($company)));
        self::assertGreaterThan(0, $count($oscar->invoices->list($company)));
        self::assertGreaterThan(0, $count($oscar->parties->list($company)));
        self::assertGreaterThan(0, $count($oscar->periods->list($company)));
        self::assertGreaterThan(0, $count($oscar->vatCodes->list($company)));
        $organizations = [];
        foreach ($oscar->organizations->list() as $item) {
            $organizations[] = $item->id;
        }
        self::assertSame([$organization], $organizations);
        $companies = [];
        foreach ($oscar->companies->list($organization) as $item) {
            $companies[] = $item->id;
        }
        self::assertSame([$company], $companies);
        self::assertGreaterThan(0, $count($oscar->files->list($company)));

        self::assertSame(self::BANK_TRANSACTION, $oscar->bankTransactions->retrieve($company, self::BANK_TRANSACTION)->id);
        self::assertSame(self::DOCUMENT, $oscar->documents->retrieve($company, self::DOCUMENT)->id);
        self::assertSame(self::INVOICE, $oscar->invoices->retrieve($company, self::INVOICE)->id);
        self::assertSame(self::PARTY, $oscar->parties->retrieve($company, self::PARTY)->id);
        self::assertSame($company, $oscar->companies->retrieve($company)->id);
        self::assertSame($organization, $oscar->organizations->retrieve($organization)->id);
        $kind = $oscar->me->retrieve()->credential->kind;
        self::assertSame('api_key', $kind instanceof \BackedEnum ? $kind->value : $kind);
        self::assertSame(self::SEEDED_FILE, $oscar->files->retrieve($company, self::SEEDED_FILE)->id);

        $oscar->generalLedger->retrieve($company, dateFrom: '2026-01-01', dateTo: '2026-09-30');
        $oscar->openItems->retrieve($company, asOf: new \DateTimeImmutable('2026-09-30'));
        $oscar->reports->balanceSheet($company, date: new \DateTimeImmutable('2026-09-30'), simplified: true);
        $oscar->reports->profitAndLoss($company, dateFrom: '2026-01-01', dateTo: '2026-09-30');
        $oscar->trialBalance->retrieve($company, period: '2026-09');
        $oscar->vatPreview->retrieve($company, periodFrom: '2026-09-01', periodTo: '2026-09-30');

        self::assertStringEndsWith('@inbox.oscaraccountant.com', $oscar->inbox->retrieve($company)->address);
        self::assertGreaterThan(0, $count($oscar->inbox->listEmails($company)));
        $oscar->inbox->rotate($company);

        $oscar->invoices->record($company, self::record());
        $oscar->invoices->cancel($company, self::INVOICE, new CancelInvoiceBodyRequest(reason: 'Entered twice'));
        $oscar->invoices->issue($company, self::INVOICE, body: new IssueInvoiceBodyRequest(number: 'S-2026-001'));
        $oscar->invoices->issue($company, self::INVOICE);
        $oscar->invoices->revise($company, self::INVOICE, ReviseInvoiceBodyRequest::fromArray(['reason' => 'Wrong date', 'revision' => self::REVISION]));
        $oscar->parties->create($company, CreatePartyPayloadRequest::fromArray(['name' => 'Supplier OÜ', 'kind' => 'supplier', 'country' => 'EE']));

        $stored = $oscar->files->upload($company, file: Upload::fromString(self::PDF, 'a.pdf', 'application/pdf'), kind: 'receipt');
        self::assertSame(self::PDF, $oscar->files->download($company, $stored->id)->contents());

        $log = $this->log();
        $bad = array_filter($log, static fn(array $entry): bool => ($entry['errors'] ?? []) !== [] || $entry['status'] >= 400);
        self::assertSame([], array_values($bad));
        $operations = array_unique(array_map(static fn(array $entry): mixed => $entry['operation_id'], $log), \SORT_REGULAR);
        self::assertCount(36, $operations);
    }

    public function testPagination(): void
    {
        $oscar = $this->oscar();
        $items = $oscar->invoices->list($this->server->companyId, limit: 1)->getIterator();
        self::assertSame([], $this->log(), 'a list sends nothing until it is read');
        $ids = [];
        foreach ($items as $invoice) {
            $ids[] = $invoice->id;
        }
        self::assertCount(3, array_unique($ids));
        $queries = array_map(static fn(array $entry): mixed => $entry['query'], $this->log());
        self::assertCount(3, $queries);

        $paginator = $oscar->invoices->list($this->server->companyId, limit: 2);
        $first = $paginator->firstPage();
        self::assertCount(2, $first->items);
        self::assertNotNull($first->next_cursor);
        $sizes = [];
        foreach ($paginator->pages() as $page) {
            $sizes[] = \count($page->items);
        }
        self::assertSame([2, 1], $sizes);
    }

    public function testErrors(): void
    {
        $oscar = $this->oscar();
        try {
            $oscar->invoices->retrieve($this->server->companyId, '01J99999999999999999999999');
            self::fail('expected NotFoundError');
        } catch (NotFoundError $error) {
            self::assertSame(404, $error->status);
            self::assertSame('not_found', $error->code);
            self::assertStringStartsWith('404 not_found', $error->getMessage());
        }
        try {
            $this->oscar('osk_wrong')->me->retrieve();
            self::fail('expected AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertSame(401, $error->status);
            self::assertSame('authentication_failed', $error->code);
            self::assertStringNotContainsString('osk_wrong', $error->getMessage());
        }
        $key = $this->server->control('POST', 'api-keys', ['scope' => 'read']);
        self::assertIsArray($key);
        self::assertIsString($key['key']);
        try {
            $this->oscar($key['key'])->invoices->record($this->server->companyId, self::record());
            self::fail('expected PermissionDeniedError');
        } catch (PermissionDeniedError $error) {
            self::assertSame('insufficient_scope', $error->code);
        }
        try {
            $oscar->invoices->list($this->server->companyId, limit: 500)->firstPage();
            self::fail('expected BadRequestError');
        } catch (BadRequestError $error) {
            self::assertSame('validation_error', $error->code);
            self::assertSame(['query.limit'], array_map(static fn(array $entry): mixed => $entry['field'] ?? null, $error->errors));
        }
    }

    public function testRetries(): void
    {
        $oscar = $this->oscar();
        $this->server->script(['match' => ['path' => '/api/me/'], 'times' => 2, 'response' => ['status' => 503, 'body' => self::BUSY]]);
        self::assertNotNull($oscar->me->retrieve()->user);
        self::assertSame([0.5, 1.0], $this->sleeps);

        $this->sleeps = [];
        $this->server->script(['match' => ['path' => '/api/me/'], 'times' => 3, 'response' => ['status' => 503, 'body' => self::BUSY]]);
        try {
            $oscar->me->retrieve();
            self::fail('expected InternalServerError');
        } catch (InternalServerError $error) {
            self::assertSame('busy', $error->code);
        }

        $this->sleeps = [];
        $throttled = ['kind' => 'json', 'value' => ['title' => 'Slow down', 'status' => 429, 'code' => 'throttled']];
        $this->server->script(['match' => ['path' => '/api/me/'], 'response' => ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => $throttled]]);
        try {
            $oscar->me->retrieve();
            self::fail('expected RateLimitError');
        } catch (RateLimitError) {
            self::assertSame([], $this->sleeps);
        }

        $this->server->script(['match' => ['path' => '/api/me/'], 'times' => 3, 'fault' => 'close']);
        try {
            $oscar->me->retrieve();
            self::fail('expected ConnectionError');
        } catch (ConnectionError) {
            self::assertSame([0.5, 1.0], $this->sleeps);
        }
    }

    public function testTimeouts(): void
    {
        $this->server->script(['match' => ['path' => '/api/me/'], 'delay_ms' => 1500]);
        $this->oscar(timeout: 0.3)->me->retrieve();
        self::assertSame([0.5], $this->sleeps);

        $this->server->script(['match' => ['path' => '/api/me/'], 'delay_ms' => 1500]);
        $this->expectException(TimeoutError::class);
        $this->oscar()->me->retrieve(new RequestOptions(timeout: 0.3, maxRetries: 0));
    }

    public function testIdempotency(): void
    {
        $oscar = $this->oscar();
        $company = $this->server->companyId;
        $this->server->script(['match' => ['operation_id' => 'commands_record_invoice'], 'response' => ['status' => 503, 'body' => self::BUSY]]);
        self::assertFalse($oscar->invoices->record($company, self::record())->replayed);
        $keys = array_map(static fn(array $entry): mixed => self::headersOf($entry)['idempotency-key'] ?? null, $this->log());
        self::assertCount(2, $keys);
        self::assertSame($keys[0], $keys[1]);
        self::assertIsString($keys[0]);
        self::assertMatchesRegularExpression(self::UUID_V4, $keys[0]);

        $this->server->reset();
        $options = new RequestOptions(idempotencyKey: 'record-inv-2026-001');
        self::assertFalse($oscar->invoices->record($company, self::record(), $options)->replayed);
        self::assertTrue($oscar->invoices->record($company, self::record(), $options)->replayed);
        $this->expectException(ConflictError::class);
        $oscar->invoices->record($company, self::record('INV-2'), $options);
    }

    public function testFiles(): void
    {
        $oscar = $this->oscar();
        $company = $this->server->companyId;
        $stored = $oscar->files->upload($company, file: Upload::fromString(self::PDF, 'receipt.pdf', 'application/pdf'), kind: 'receipt', receivedOn: new \DateTimeImmutable('2026-09-20'));
        self::assertSame('receipt.pdf', $stored->name);
        self::assertSame(\strlen(self::PDF), $stored->size_bytes);
        self::assertSame(hash('sha256', self::PDF), $stored->sha256);
        $download = $oscar->files->download($company, $stored->id);
        self::assertSame('receipt.pdf', $download->filename);
        self::assertStringStartsWith('application/pdf', (string) $download->contentType);
        self::assertSame(self::PDF, implode('', iterator_to_array($download->stream(), false)));

        $path = sys_get_temp_dir() . '/scan-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, self::PDF);
        try {
            self::assertSame(basename($path), $oscar->files->upload($company, file: Upload::fromPath($path))->name);
            $target = $path . '.out';
            $seeded = $oscar->files->download($company, self::SEEDED_FILE);
            $size = $seeded->saveTo($target);
            self::assertSame($oscar->files->retrieve($company, self::SEEDED_FILE)->sha256, hash_file('sha256', $target));
            self::assertSame(filesize($target), $size);
            self::assertSame('invoice.pdf', $seeded->filename);
            unlink($target);
        } finally {
            unlink($path);
        }
    }
}
