<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * The client got an option it cannot use, for example a base URL that is not allowed.
 */
class ConfigurationError extends \InvalidArgumentException implements OscarError {}
