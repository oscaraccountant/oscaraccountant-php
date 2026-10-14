<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use OscarAccountant\Errors\ApiError;
use OscarAccountant\Errors\AuthenticationError;
use OscarAccountant\Errors\BadRequestError;
use OscarAccountant\Errors\ConflictError;
use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\InternalServerError;
use OscarAccountant\Errors\NotFoundError;
use OscarAccountant\Errors\OscarError;
use OscarAccountant\Errors\PermissionDeniedError;
use OscarAccountant\Errors\RateLimitError;
use OscarAccountant\Errors\ReasonPhrase;
use OscarAccountant\Errors\ResponseValidationError;
use OscarAccountant\Errors\TimeoutError;
use OscarAccountant\Errors\UnprocessableEntityError;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function testClassOfEachStatus(): void
    {
        $expected = [
            400 => BadRequestError::class,
            401 => AuthenticationError::class,
            403 => PermissionDeniedError::class,
            404 => NotFoundError::class,
            409 => ConflictError::class,
            418 => ApiError::class,
            422 => UnprocessableEntityError::class,
            429 => RateLimitError::class,
            500 => InternalServerError::class,
            503 => InternalServerError::class,
        ];
        foreach ($expected as $status => $class) {
            self::assertSame($class, ApiError::classOf($status));
        }
    }

    public function testProblem(): void
    {
        $body = (string) json_encode([
            'code' => 'validation_error',
            'title' => 'Not valid',
            'detail' => 'The request is not valid.',
            'errors' => [['field' => 'limit'], 'not an object', [1, 2], []],
        ]);
        $error = ApiError::fromResponse(400, ['x-request-id' => 'req_1'], $body, 'Bad Request');
        self::assertInstanceOf(BadRequestError::class, $error);
        self::assertInstanceOf(OscarError::class, $error);
        self::assertSame(400, $error->status);
        self::assertSame('validation_error', $error->code);
        self::assertSame('validation_error', $error->getCode());
        self::assertSame('Not valid', $error->title);
        self::assertSame([['field' => 'limit'], []], $error->errors);
        self::assertSame('req_1', $error->requestId);
        self::assertSame('400 validation_error: The request is not valid. (request req_1)', $error->getMessage());
    }

    public function testNotAProblem(): void
    {
        $error = ApiError::fromResponse(502, ['x-request-id' => ''], '', '');
        self::assertSame('unknown', $error->code);
        self::assertSame('Bad Gateway', $error->title);
        self::assertNull($error->detail);
        self::assertNull($error->requestId);
        self::assertSame('502 unknown: Bad Gateway', $error->getMessage());
        $long = ApiError::fromResponse(599, [], str_repeat('é', 2500) . "\xff", 'Odd');
        self::assertSame('Odd', $long->title);
        self::assertSame(2000, preg_match_all('/./u', (string) $long->detail));
        $title = ApiError::fromResponse(404, [], '{"code":"not_found","title":"Gone"}', '');
        self::assertSame('404 not_found: Gone', $title->getMessage());
        $bare = ApiError::fromResponse(499, [], '{"code":1}', '');
        self::assertNull($bare->title);
        self::assertSame('499 unknown: {"code":1}', $bare->getMessage());
        self::assertSame('', ReasonPhrase::of(299));
    }

    public function testErrorsWithoutResponse(): void
    {
        $auth = AuthenticationError::noCredential('No key.');
        self::assertNull($auth->status);
        self::assertSame('no_credential', $auth->code);
        foreach ([new ConnectionError('x'), new TimeoutError('x'), new ResponseValidationError('x', 200, null)] as $error) {
            self::assertInstanceOf(OscarError::class, $error);
            self::assertInstanceOf(\RuntimeException::class, $error);
        }
    }
}
