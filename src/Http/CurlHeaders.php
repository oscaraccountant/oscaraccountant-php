<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

/**
 * The status line and the headers of the last response of a curl transfer.
 *
 * @internal
 */
final class CurlHeaders
{
    public int $status = 0;

    public string $reason = '';

    /**
     * @var array<string, string>
     */
    public array $headers = [];

    private bool $complete = false;

    /**
     * The `CURLOPT_HEADERFUNCTION` callback: one line at a time.
     */
    public function collect(\CurlHandle $handle, string $line): int
    {
        $text = rtrim($line, "\r\n");
        if (preg_match('#^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$#', $text, $match) === 1) {
            $this->status = (int) $match[1];
            $this->reason = trim($match[2] ?? '');
            $this->headers = [];
            $this->complete = false;
        } elseif ($text === '') {
            $this->complete = $this->status >= 200;
        } elseif (str_contains($text, ':')) {
            [$name, $value] = explode(':', $text, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name] . ', ' . $value : $value;
        }

        return \strlen($line);
    }

    /**
     * The empty line after the headers of a final response (not a `1xx`) is in.
     */
    public function complete(): bool
    {
        return $this->complete;
    }
}
