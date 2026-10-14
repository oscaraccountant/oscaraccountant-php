<?php

declare(strict_types=1);

namespace OscarAccountant;

use OscarAccountant\Errors\ApiError;
use OscarAccountant\Errors\ConfigurationError;
use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\ResponseValidationError;
use OscarAccountant\Errors\TimeoutError;
use OscarAccountant\Generated\GeneratedClient;
use OscarAccountant\Generated\Runtime\InvalidValue;
use OscarAccountant\Generated\Runtime\JsonModel;
use OscarAccountant\Generated\Runtime\Request;
use OscarAccountant\Http\CurlTransport;
use OscarAccountant\Http\Psr18Transport;
use OscarAccountant\Http\Response;
use OscarAccountant\Http\Transport;
use OscarAccountant\Internal\BaseUrl;
use OscarAccountant\Internal\Call;
use OscarAccountant\Internal\Credential;
use OscarAccountant\Internal\Credentials;
use OscarAccountant\Internal\Hooks;
use OscarAccountant\Internal\Json;
use OscarAccountant\Internal\RequestBuilder;
use OscarAccountant\Internal\Retry;
use OscarAccountant\Internal\Settings;
use OscarAccountant\Internal\UserAgent;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The client of the Oscar API.
 *
 * ```php
 * $oscar = new Oscar();                                   // reads OSCAR_API_KEY
 * foreach ($oscar->invoices->list($companyId, state: 'issued') as $invoice) {
 *     echo $invoice->number, "\n";
 * }
 * ```
 *
 * The credential is, in this order: `apiKey` or `tokenProvider`, then `OSCAR_API_KEY`, then the login of the
 * `oscar` CLI when `credential: 'cli'` or `OSCAR_USE_CLI_LOGIN=1`. The base URL is `baseUrl`, then
 * `OSCAR_BASE_URL`, then `DEFAULT_BASE_URL`.
 */
final class Oscar extends GeneratedClient
{
    /**
     * The base URL when neither the option `baseUrl` nor `OSCAR_BASE_URL` is set: the production origin.
     * The API is under `/api/` and OAuth under `/oauth/`.
     */
    public const DEFAULT_BASE_URL = 'https://oscaraccountant.com';

    /**
     * The timeout of one attempt, in seconds.
     */
    public const DEFAULT_TIMEOUT = 60.0;

    /**
     * The retries after the first attempt. 0 turns them off.
     */
    public const DEFAULT_MAX_RETRIES = 2;

    private readonly Settings $settings;

    private readonly Credential $credential;

    private readonly Transport $transport;

    /**
     * @param string|null $apiKey an organization API key (`osk_...`)
     * @param (callable(): string)|null $tokenProvider called before each attempt; it returns an access token
     * @param string|null $credential `'cli'`: the stored login of the `oscar` CLI, when no other credential is set
     * @param string|null $baseUrl the origin of the API, for example `http://localhost:3500`
     * @param float $timeout the timeout of each attempt, in seconds. It has no effect on a PSR-18 client,
     *                       whose own timeout applies
     * @param int $maxRetries the retries after the first attempt; 0 turns them off
     * @param ClientInterface|null $httpClient a PSR-18 client, with `$requestFactory` and `$streamFactory`
     * @param array<string, string> $headers headers for every call
     * @param string|null $profile the profile of the CLI login (else `OSCAR_PROFILE`)
     * @param Transport|null $transport a transport of your own, for example on `wp_remote_request`
     * @param Hooks|null $hooks the clock, the sleep, the jitter and the UUID source; for the tests
     *
     * @throws ConfigurationError
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?callable $tokenProvider = null,
        ?string $credential = null,
        ?string $baseUrl = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $headers = [],
        ?string $profile = null,
        ?Transport $transport = null,
        ?Hooks $hooks = null,
    ) {
        if (\PHP_INT_SIZE !== 8) {
            throw new ConfigurationError('The Oscar SDK needs a 64-bit PHP.');
        }
        if ($timeout <= 0) {
            throw new ConfigurationError('timeout must be more than 0 seconds.');
        }
        if ($maxRetries < 0) {
            throw new ConfigurationError('maxRetries must be 0 or more.');
        }
        $hooks ??= new Hooks();
        $resolvedUrl = BaseUrl::resolve($baseUrl);
        $this->settings = new Settings($resolvedUrl, $timeout, $maxRetries, UserAgent::build(), $headers, $hooks);
        $this->credential = Credentials::resolve($apiKey, $tokenProvider, $credential, $profile, $resolvedUrl, $hooks);
        $this->transport = self::transport($transport, $httpClient, $requestFactory, $streamFactory);
        parent::__construct();
    }

    /**
     * The normalized base URL of the API.
     */
    public function baseUrl(): string
    {
        return $this->settings->baseUrl;
    }

    /**
     * @internal the runtime of the generated resources
     */
    public function request(Request $request, string $type, ?RequestOptions $options): JsonModel
    {
        $response = $this->send(RequestBuilder::prepare($this->settings, $request, $options), false);
        $requestId = $response->header('x-request-id');
        try {
            return $type::fromJson(Json::decode($response->body()));
        } catch (\JsonException|InvalidValue $error) {
            throw new ResponseValidationError(
                \sprintf('The %d response is not a valid %s: %s', $response->status, self::shortName($type), $error->getMessage()),
                $response->status,
                $requestId,
                $error,
            );
        }
    }

    /**
     * @internal the runtime of the generated resources
     */
    public function requestNoContent(Request $request, ?RequestOptions $options): void
    {
        $this->send(RequestBuilder::prepare($this->settings, $request, $options), false)->body();
    }

    /**
     * @internal the runtime of the generated resources
     */
    public function paginate(Request $request, string $pageType, string $itemType, ?RequestOptions $options): Paginator
    {
        return new Paginator(fn(Request $page): JsonModel => $this->request($page, $pageType, $options), $request);
    }

    /**
     * @internal the runtime of the generated resources
     */
    public function download(Request $request, ?RequestOptions $options): Download
    {
        return new Download($this->send(RequestBuilder::prepare($this->settings, $request, $options), true));
    }

    /**
     * Send the call, with its retries. A response of 400 or above becomes an `ApiError`.
     */
    private function send(Call $call, bool $stream): Response
    {
        $retry = 0;
        $refreshed = false;
        while (true) {
            $attempt = $call->attempt($this->credential->token());
            $retryAfter = null;
            try {
                $response = $this->transport->send($attempt, $stream);
            } catch (ConnectionError|TimeoutError $error) {
                $retryable = true;
                $response = null;
            }
            if ($response !== null) {
                if ($response->status < 400) {
                    return $response;
                }
                $error = ApiError::fromResponse($response->status, $response->headers(), $response->body(), $response->reason);
                if ($error->status === 401 && $this->credential->refreshable() && !$refreshed) {
                    $refreshed = true;
                    $this->credential->invalidate();
                    continue;
                }
                $retryable = Retry::isRetryableStatus($response->status, \is_string($error->code) ? $error->code : null);
                $retryAfter = $response->header('retry-after');
            }
            ++$retry;
            $delay = null;
            if ($retryable && $call->retryable() && $retry <= $call->maxRetries) {
                $hooks = $this->settings->hooks;
                $delay = Retry::delay($retry, $retryAfter, ($hooks->clock)(), ($hooks->jitter)());
            }
            if ($delay === null) {
                throw $error;
            }
            ($this->settings->hooks->sleep)($delay);
        }
    }

    private static function transport(
        ?Transport $transport,
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
    ): Transport {
        if ($transport !== null) {
            if ($httpClient !== null) {
                throw new ConfigurationError('Pass transport or httpClient, not both.');
            }

            return $transport;
        }
        if ($httpClient === null) {
            return new CurlTransport();
        }
        if ($requestFactory === null || $streamFactory === null) {
            throw new ConfigurationError('A PSR-18 httpClient needs the PSR-17 requestFactory and streamFactory too.');
        }

        return new Psr18Transport($httpClient, $requestFactory, $streamFactory);
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
