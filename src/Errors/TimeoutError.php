<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * An attempt ran out of time. Raised after the last retry.
 */
class TimeoutError extends \RuntimeException implements OscarError {}
