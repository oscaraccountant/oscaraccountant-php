<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\TimeoutError;

/**
 * Sends one attempt of a call. The client adds the retries, the credential and the errors.
 *
 * `CurlTransport` is the default. `Psr18Transport` sends through a PSR-18 client. A WordPress plugin can
 * write its own on `wp_remote_request`.
 */
interface Transport
{
    /**
     * Send the request and give the response, also when its status is 400 or above.
     *
     * @param bool $stream the body is read when the caller reads it (a download); the timeout of the attempt
     *                     then covers the status line and the headers only
     *
     * @throws ConnectionError when the request gets no response
     * @throws TimeoutError when the attempt runs out of time
     */
    public function send(HttpRequest $request, bool $stream): Response;
}
