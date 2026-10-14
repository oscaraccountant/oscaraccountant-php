<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * Every error of the SDK implements this interface: `catch (OscarError $e)` catches all of them.
 *
 * It is an interface because a library throws exceptions, and the errors extend the exception class of PHP
 * that fits them best.
 */
interface OscarError extends \Throwable {}
