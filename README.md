# oscaraccountant/sdk

The PHP SDK of the Oscar accounting API.

- PHP 8.2 and later, 64-bit.
- No Composer dependency at run time: only the PHP extensions `curl` and
  `json`. So it cannot conflict with another package, also in a WordPress
  plugin.
- Typed models for every request and response, checked by PHPStan at level
  `max`.
- Your own PSR-18 HTTP client if you want one (Guzzle, Symfony HttpClient,
  the client of Laravel).

## Install

```bash
composer require oscaraccountant/sdk
```

## Quick start

```php
use OscarAccountant\Oscar;

$oscar = new Oscar();  // reads OSCAR_API_KEY

$me = $oscar->me->retrieve();
foreach ($oscar->invoices->list($companyId, state: 'issued') as $invoice) {
    echo $invoice->id, ' ', $invoice->number, "\n";
}

$pnl = $oscar->reports->profitAndLoss($companyId, dateFrom: '2026-09-01', dateTo: '2026-09-30');
```

A method takes the ids of the path first, in the order of the path. Give
every other argument by name: the query parameters, the fields of an
upload, `body` and `options`. A date argument takes a `\DateTimeInterface`
or a `YYYY-MM-DD` string.

## Authentication

The client finds its credential in this order. The first match wins.

1. The option `apiKey: 'osk_...'`, or `tokenProvider: $callback`. A token
   provider returns an access token. The client calls it before each request
   and each retry.
2. The environment variable `OSCAR_API_KEY`.
3. The login of the `oscar` CLI, **only when you ask for it** (see below).
4. Otherwise each call throws `AuthenticationError` and sends nothing.

An empty string counts as not set. The client sends the credential as
`Authorization: Bearer <value>`.

### Use the login of the `oscar` CLI

On a developer machine, you can use the login of the `oscar` CLI instead of
an API key:

```bash
npx @oscaraccountant/cli login
```

```php
$oscar = new Oscar(credential: 'cli');                    // or set OSCAR_USE_CLI_LOGIN=1
$oscar = new Oscar(credential: 'cli', profile: 'work');   // or set OSCAR_PROFILE=work
```

The SDK never starts a process unless you ask for it. So a server with no
`OSCAR_API_KEY` fails, and does not use the login of a developer by accident.

When you ask for it, the SDK:

- finds `oscar` in the directories of `PATH` only, never in the current
  directory, and runs it by its absolute path through `proc_open`, with no
  shell;
- runs `oscar --version` once and requires `oscar-cli <version>`;
- runs `oscar auth token --json --base-url <base URL>` (and `--profile`),
  with a timeout of 30 seconds;
- refuses a token for another base URL;
- keeps the token in memory until 60 seconds before it expires. After a 401,
  it asks the CLI once more before it throws the error;
- never logs the token and never writes it to disk.

Many shared hosts disable `proc_open`. There the CLI login throws
`AuthenticationError`: use an API key.

## Configuration

| Option | Default | What it does |
|---|---|---|
| `apiKey` | `OSCAR_API_KEY` | The API key |
| `tokenProvider` | none | A callable that returns an access token |
| `credential` | none | `'cli'`: use the login of the `oscar` CLI |
| `profile` | `OSCAR_PROFILE` | The profile of the CLI login |
| `baseUrl` | `OSCAR_BASE_URL`, else `Oscar::DEFAULT_BASE_URL` (`https://oscaraccountant.com`) | The origin of the API |
| `timeout` | `60.0` | The timeout of one attempt, in seconds |
| `maxRetries` | `2` | The retries after the first attempt. `0` turns them off |
| `headers` | none | Headers for every call |
| `httpClient`, `requestFactory`, `streamFactory` | none | A PSR-18 client and its PSR-17 factories (see below) |
| `transport` | `CurlTransport` | A transport of your own (`OscarAccountant\Http\Transport`) |

The base URL must use `https`. `http` is allowed only for `localhost`,
`127.0.0.1`, `[::1]` and hosts that end in `.personal`.

Each method takes `options: new RequestOptions(...)` for one call:

```php
use OscarAccountant\RequestOptions;

$oscar->invoices->record($companyId, $payload, options: new RequestOptions(
    idempotencyKey: 'my-key',
    timeout: 10.0,
    maxRetries: 0,
    headers: ['X-Trace' => 'abc'],
));
```

`headers` cannot replace `Authorization` or `Idempotency-Key`.

## Models and enums

The models are in `OscarAccountant\Generated\Models`, the enums in
`OscarAccountant\Generated\Enums`. A property of a model has the name of the
field in the API, so it reads the same as the API reference:

```php
$page = $oscar->invoices->list($companyId, limit: 50)->firstPage();
echo count($page->items), ' ', $page->next_cursor;
```

- A model keeps a field that this version of the SDK does not know:
  `$model->extra()` gives it. `$model->toArray()` gives the model as decoded
  JSON, with the names of the API.
- In such a field and in a `mixed` property (untyped JSON), a JSON object is
  a `\stdClass` and a JSON array is a PHP array. So `json_encode` writes
  `{}` back as `{}`, not as `[]`.
- An enum field is the enum case, or a string when this version does not
  know the value. So a new value of the server does not break your code, and
  a `match` on it needs a `default` arm.
- Money and other decimals are strings, never a `float`. A date is a
  `YYYY-MM-DD` string and a date-time an RFC 3339 string.

A request body is a model with named arguments. A field you do not give is
not sent; a field you give as `null` is sent as `null`:

```php
use OscarAccountant\Generated\Models\CancelInvoiceBodyRequest;

$oscar->invoices->cancel($companyId, $invoiceId, new CancelInvoiceBodyRequest(reason: 'Entered twice'));
```

`Model::fromArray($data)` makes a model from decoded JSON, for example a
body you keep in a configuration file.

## Pagination

A list method returns a `Paginator`:

```php
$invoices = $oscar->invoices->list($companyId, limit: 50);

$page = $invoices->firstPage();              // one page: $page->items, $page->next_cursor
foreach ($invoices as $invoice) { /* ... */ } // every item of every page
foreach ($invoices->pages() as $page) { /* ... */ }
```

The paginator sends a request only when you need the next item. Each page is
a separate `GET` with its own retries.

## Errors

Every error implements the interface `OscarAccountant\Errors\OscarError`, so
`catch (OscarError $e)` catches all of them.

| Error | When |
|---|---|
| `ApiError` | The API answered with a status of 400 or above |
| `BadRequestError` | 400 |
| `AuthenticationError` | 401, or no credential (then `$status` is `null`) |
| `PermissionDeniedError` | 403 |
| `NotFoundError` | 404 |
| `ConflictError` | 409 |
| `UnprocessableEntityError` | 422 |
| `RateLimitError` | 429 |
| `InternalServerError` | 500 and above |
| `ConnectionError` | No response: DNS, a refused or reset connection, TLS |
| `TimeoutError` | An attempt ran out of time |
| `ResponseValidationError` | A success response that does not match its type |
| `ConfigurationError` | An option that the client cannot use |
| `UsageError` | A call that the client cannot send, for example an empty id |

An `ApiError` has `$status`, `$code` (stable and machine-readable, for
example `validation_error`; `getCode()` gives it too), `$title`, `$detail`,
`$errors` (one entry per invalid field) and `$requestId` (the `X-Request-Id`
of the response):

```php
use OscarAccountant\Errors\ApiError;
use OscarAccountant\Errors\NotFoundError;

try {
    $oscar->invoices->retrieve($companyId, $invoiceId);
} catch (NotFoundError) {
    // ...
} catch (ApiError $error) {
    echo $error->status, ' ', $error->code, ' ', $error->detail, ' ', $error->requestId;
}
```

## Retries and idempotency

The client sends a request again only when this is safe: a `GET`, or a
request with an `Idempotency-Key`. It retries on a connection error, a
timeout, and the statuses 408, 429, 500, 502, 503, 504, and 409 with the
code `idempotency_in_progress`.

- The delay before retry `n` is `min(8, 0.5 * 2^(n-1))` seconds, less up to
  25 percent of random jitter.
- When the response has `Retry-After`, that delay wins. A `Retry-After` of
  more than 60 seconds ends the retries.
- The client sends an `Idempotency-Key` (a UUID version 4) on every operation
  that accepts it, and the same key on every retry. Give your own key with
  `new RequestOptions(idempotencyKey: ...)`.

## Files

Upload a file with `files->upload`:

```php
use OscarAccountant\Upload;

$stored = $oscar->files->upload($companyId, file: Upload::fromPath('invoice.pdf', contentType: 'application/pdf'), kind: 'purchase_invoice');
$stored = $oscar->files->upload($companyId, file: Upload::fromString($bytes, 'invoice.pdf', 'application/pdf'));
$stored = $oscar->files->upload($companyId, file: Upload::fromStream($handle));
```

The SDK reads the file once, so a retry sends the same bytes.

Download the bytes of a file with `files->download`:

```php
$download = $oscar->files->download($companyId, $fileId);
echo $download->filename, ' ', $download->contentType, ' ', $download->contentLength;
$download->saveTo('/tmp/' . ($download->filename ?? 'file'));
```

`$download->stream()` gives the bytes in chunks as they arrive, and
`$download->contents()` gives all of them. Read a download once.

## Your own HTTP client (PSR-18)

By default the SDK sends its requests with the PHP extension `curl`. To send
them through the HTTP client of your application, give a PSR-18 client and
its PSR-17 factories:

```php
$factory = new \GuzzleHttp\Psr7\HttpFactory();
$oscar = new Oscar(
    httpClient: new \GuzzleHttp\Client(['timeout' => 60]),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

PSR-18 has no timeout for one request. With a PSR-18 client, the timeout is
the one of the client you built, and the option `timeout` has no effect.

## In a WordPress plugin

The SDK has no Composer dependency at run time, defines no global function
and no global constant, and uses `ext-curl`, which WordPress uses too. So you
can bundle it in a plugin with no PHP-Scoper or Strauss step. If your plugin
must send through `wp_remote_request`, write a class that implements
`OscarAccountant\Http\Transport` and give it as `transport:`.

## Development

The resources and the models in `src/Generated/` are generated from the
OpenAPI schema of the API. Do not edit them. The hand-written runtime is the
rest of `src/`.

The gates:

```bash
composer install
composer validate --strict
vendor/bin/php-cs-fixer check
vendor/bin/phpstan analyse                               # level max
vendor/bin/phpunit --coverage-clover build/clover.xml    # with pcov or Xdebug
php tests/check-coverage.php build/clover.xml 90
```

`vendor/bin/phpunit` also has two suites that need files of the source
repository of Oscar. In this repository they are skipped:

| Suite | What it does |
|---|---|
| `tests/Conformance/` | Plays every conformance case of the shared client rules with a fake transport |
| `tests/Integration/` (group `integration`) | Starts the mock server of the API as a process on a free port and calls every operation over real HTTP |

## Documentation

The guides and the reference of the API are at
<https://oscaraccountant.com/docs>.

## License

MIT. Copyright (c) 2026 OscarAI OÜ.
