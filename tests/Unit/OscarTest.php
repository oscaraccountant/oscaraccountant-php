<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use OscarAccountant\Errors\AuthenticationError;
use OscarAccountant\Errors\ConfigurationError;
use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\NotFoundError;
use OscarAccountant\Errors\OscarError;
use OscarAccountant\Errors\ResponseValidationError;
use OscarAccountant\Errors\UsageError;
use OscarAccountant\Generated\Models\Me;
use OscarAccountant\Generated\Models\RecordInvoicePayloadRequest;
use OscarAccountant\Http\CurlTransport;
use OscarAccountant\Http\Psr18Transport;
use OscarAccountant\Http\Response;
use OscarAccountant\Internal\Hooks;
use OscarAccountant\Oscar;
use OscarAccountant\RequestOptions;
use OscarAccountant\Tests\Unit\Support\ScriptedTransport;
use OscarAccountant\Upload;
use OscarAccountant\Version;
use PHPUnit\Framework\TestCase;

final class OscarTest extends TestCase
{
    use Environment;

    private const ME = [
        'user' => ['id' => 'usr0000001', 'email' => 'dev@example.com', 'name' => 'Dev', 'is_staff' => false, 'ui_mode' => 'simple'],
        'has_totp' => false,
        'staff_mfa_required' => false,
        'email_verified' => true,
        'credential' => ['kind' => 'api_key', 'scopes' => ['read', 'write'], 'staff_key' => false],
        'organizations' => [],
        'companies' => [],
        'features' => ['agent' => false, 'inbox' => false],
        'cli_min_version' => '0.1.0',
    ];

    /**
     * @var list<float>
     */
    private array $sleeps = [];

    /**
     * @param array<string, string> $headers
     */
    private function client(ScriptedTransport $transport, array $headers = [], int $maxRetries = 2): Oscar
    {
        $hooks = new Hooks(
            clock: static fn(): float => 1_790_000_000.0,
            sleep: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            jitter: static fn(): float => 0.0,
            uuid: static fn(): string => '00000000-0000-4000-8000-000000000000',
        );

        return new Oscar(apiKey: 'osk_test', maxRetries: $maxRetries, headers: $headers, transport: $transport, hooks: $hooks);
    }

    public function testDefaults(): void
    {
        $oscar = new Oscar(apiKey: 'osk_test');
        self::assertSame('https://oscaraccountant.com', $oscar->baseUrl());
        self::assertSame(Oscar::DEFAULT_BASE_URL, $oscar->baseUrl());
        self::assertSame(60.0, Oscar::DEFAULT_TIMEOUT);
        self::assertSame(2, Oscar::DEFAULT_MAX_RETRIES);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Version::VERSION);
        self::assertInstanceOf(CurlTransport::class, (new \ReflectionProperty(Oscar::class, 'transport'))->getValue($oscar));
    }

    public function testRefusedOptions(): void
    {
        foreach ([
            static fn(): Oscar => new Oscar(apiKey: 'k', timeout: 0),
            static fn(): Oscar => new Oscar(apiKey: 'k', maxRetries: -1),
            static fn(): Oscar => new Oscar(apiKey: 'k', baseUrl: 'http://example.com'),
            static fn(): Oscar => new Oscar(apiKey: 'k', tokenProvider: static fn(): string => 't'),
            static fn(): Oscar => new Oscar(apiKey: 'k', credential: 'keychain'),
            static fn(): Oscar => new Oscar(apiKey: 'k', transport: new ScriptedTransport(), httpClient: new Psr18Client()),
            static fn(): Oscar => new Oscar(apiKey: 'k', httpClient: new Psr18Client()),
        ] as $make) {
            try {
                $make();
                self::fail('The client accepted an option it must refuse.');
            } catch (ConfigurationError $error) {
                self::assertInstanceOf(OscarError::class, $error);
                self::assertInstanceOf(\InvalidArgumentException::class, $error);
            }
        }
    }

    public function testPsr18ClientMakesThePsr18Transport(): void
    {
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $oscar = new Oscar(apiKey: 'k', httpClient: new Psr18Client(), requestFactory: $factory, streamFactory: $factory);
        self::assertInstanceOf(Psr18Transport::class, (new \ReflectionProperty(Oscar::class, 'transport'))->getValue($oscar));
    }

    public function testRequestSendsTheCredentialAndParsesTheModel(): void
    {
        $transport = new ScriptedTransport([ScriptedTransport::json(200, self::ME)]);
        $oscar = $this->client($transport, headers: ['X-Extra' => 'yes', 'Authorization' => 'Bearer nope']);
        $me = $oscar->me->retrieve(new RequestOptions(headers: ['x-extra' => 'call', 'Idempotency-Key' => 'no']));
        self::assertInstanceOf(Me::class, $me);
        self::assertSame('dev@example.com', $me->user?->email);
        $request = $transport->requests[0];
        self::assertSame('Bearer osk_test', $request->header('authorization'));
        self::assertSame('call', $request->header('X-Extra'));
        self::assertNull($request->header('idempotency-key'));
        self::assertSame('application/json', $request->header('accept'));
        self::assertSame(60.0, $request->timeout);
    }

    public function testJsonBodyAndIdempotencyKey(): void
    {
        $outcome = ['id' => '01J9', 'state' => 'draft', 'warnings' => []];
        $transport = new ScriptedTransport([ScriptedTransport::json(201, $outcome)]);
        $body = RecordInvoicePayloadRequest::fromArray([
            'direction' => 'purchase',
            'party_id' => 'p',
            'revision' => ['invoice_date' => '2026-09-01', 'currency' => 'EUR', 'lines' => []],
        ]);
        try {
            $this->client($transport)->invoices->record('cmp1', $body);
        } catch (ResponseValidationError) {
            // The outcome above is not a full RecordInvoiceOutcome; the request is what this test reads.
        }
        $request = $transport->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('00000000-0000-4000-8000-000000000000', $request->header('idempotency-key'));
        $sent = json_decode((string) $request->body, true);
        self::assertIsArray($sent);
        self::assertSame('purchase', $sent['direction']);
        self::assertArrayNotHasKey('number', $sent);
    }

    public function testRetriesAGetThenRaisesTheLastError(): void
    {
        $transport = new ScriptedTransport([
            new ConnectionError('down'),
            new Response(503, ['retry-after' => '2'], ''),
            new Response(404, ['content-type' => 'application/problem+json', 'x-request-id' => 'req_1'], '{"code":"not_found","detail":"Gone."}'),
        ]);
        try {
            $this->client($transport)->me->retrieve();
            self::fail('expected an error');
        } catch (NotFoundError $error) {
            self::assertSame('404 not_found: Gone. (request req_1)', $error->getMessage());
            self::assertSame('not_found', $error->getCode());
        }
        self::assertSame([0.5, 2.0], $this->sleeps);
    }

    public function testAPostWithoutKeyIsSentOnce(): void
    {
        $transport = new ScriptedTransport([new ConnectionError('down')]);
        $this->expectException(ConnectionError::class);
        try {
            $this->client($transport)->inbox->rotate('cmp1');
        } finally {
            self::assertCount(1, $transport->requests);
        }
    }

    public function testInvalidResponseBody(): void
    {
        $transport = new ScriptedTransport([
            new Response(200, ['x-request-id' => 'req_2'], 'not json'),
            ScriptedTransport::json(200, ['user' => 'not an object', 'credential' => []]),
        ]);
        $oscar = $this->client($transport);
        foreach ([1, 2] as $attempt) {
            try {
                $oscar->me->retrieve();
                self::fail('expected ResponseValidationError ' . $attempt);
            } catch (ResponseValidationError $error) {
                self::assertSame(200, $error->status);
                self::assertStringContainsString('not a valid Me', $error->getMessage());
            }
        }
    }

    public function testMissingCredentialSendsNothing(): void
    {
        $this->clearOscarEnvironment();
        $transport = new ScriptedTransport();
        $oscar = new Oscar(transport: $transport);
        try {
            $oscar->me->retrieve();
            self::fail('expected AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertNull($error->status);
            self::assertSame('no_credential', $error->code);
            self::assertStringContainsString('OSCAR_API_KEY', $error->getMessage());
        }
        self::assertSame([], $transport->requests);
    }

    public function testRequestNoContentAndDownload(): void
    {
        $transport = new ScriptedTransport([
            new Response(204, [], ''),
            new Response(200, ['content-type' => 'application/pdf', 'content-length' => '4', 'content-disposition' => 'attachment; filename="../a.pdf"'], ['%P', 'DF']),
        ]);
        $oscar = $this->client($transport);
        $oscar->files->list('cmp1');
        $sent = \count($transport->requests);
        self::assertSame(0, $sent, 'a list sends nothing until it is read');
        $request = new \OscarAccountant\Generated\Runtime\Request('x', 'DELETE', '/api/x/', [], [], null, false, 'none');
        $oscar->requestNoContent($request, null);
        $download = $oscar->files->download('cmp1', 'fil1');
        self::assertSame([false, true], $transport->streams);
        self::assertSame('*/*', $transport->request(1)->header('accept'));
        self::assertSame('application/pdf', $download->contentType);
        self::assertSame(4, $download->contentLength);
        self::assertSame('a.pdf', $download->filename);
        self::assertSame('%PDF', $download->contents());
    }

    public function testUploadIsMultipart(): void
    {
        $transport = new ScriptedTransport([new ConnectionError('down'), new Response(500, [], 'boom')]);
        try {
            $this->client($transport, maxRetries: 1)->files->upload('cmp1', file: Upload::fromString('%PDF', 'a.pdf', 'application/pdf'), kind: 'invoice');
            self::fail('expected an error');
        } catch (\OscarAccountant\Errors\InternalServerError $error) {
            self::assertSame('unknown', $error->code);
            self::assertSame('Internal Server Error', $error->title);
            self::assertSame('boom', $error->detail);
        }
        self::assertCount(2, $transport->requests);
        self::assertSame($transport->requests[0]->body, $transport->requests[1]->body);
        self::assertStringStartsWith('multipart/form-data; boundary=oscar-', (string) $transport->requests[0]->header('content-type'));
        self::assertStringContainsString("name=\"file\"; filename=\"a.pdf\"\r\nContent-Type: application/pdf\r\n\r\n%PDF\r\n", (string) $transport->requests[0]->body);
    }

    public function testCallOptionsAreChecked(): void
    {
        $oscar = $this->client(new ScriptedTransport());
        foreach ([new RequestOptions(timeout: 0.0), new RequestOptions(maxRetries: -1)] as $options) {
            try {
                $oscar->me->retrieve($options);
                self::fail('expected UsageError');
            } catch (UsageError $error) {
                self::assertInstanceOf(\InvalidArgumentException::class, $error);
            }
        }
        $this->expectException(UsageError::class);
        $oscar->companies->retrieve('..');
    }
}
