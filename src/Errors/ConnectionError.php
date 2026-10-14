<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * The request got no response: DNS, a refused or reset connection, TLS. Raised after the last retry.
 */
class ConnectionError extends \RuntimeException implements OscarError {}
