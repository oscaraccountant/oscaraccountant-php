<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

/**
 * The bytes of a download that curl received and the caller did not read yet.
 *
 * @internal
 */
final class CurlBuffer
{
    private string $data = '';

    /**
     * The `CURLOPT_WRITEFUNCTION` callback.
     */
    public function write(\CurlHandle $handle, string $data): int
    {
        $this->data .= $data;

        return \strlen($data);
    }

    /**
     * The bytes received since the last call.
     */
    public function take(): string
    {
        $data = $this->data;
        $this->data = '';

        return $data;
    }
}
