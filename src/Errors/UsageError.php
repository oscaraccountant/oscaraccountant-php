<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * A call the client cannot send as given, for example a path value that is empty, `.` or `..`.
 *
 * The call sends nothing.
 */
class UsageError extends \InvalidArgumentException implements OscarError {}
