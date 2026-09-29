<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\ApiError;

final class ApiErrorTest extends TestCase
{
    public function testFromArrayReadsAnEntryOfTheErrorsList(): void
    {
        // The shape from the specification's Error schema.
        $error = ApiError::fromArray([
            'path' => '/packages/id/90014858123',
            'message' => 'An error occurred while communicating with the carrier API',
            'details' => 'Timeout',
            'code' => 'carrierConnectionError',
        ]);

        self::assertSame('/packages/id/90014858123', $error->path);
        self::assertSame('An error occurred while communicating with the carrier API', $error->message);
        self::assertSame('Timeout', $error->details);
        self::assertSame('carrierConnectionError', $error->code);
    }

    public function testFromArrayReadsTheFlat401Body(): void
    {
        // Seen on the sandbox for a request without an Authorization header.
        $error = ApiError::fromArray([
            'code' => 'access_denied',
            'message' => 'The resource owner or authorization server denied the request.',
            'details' => 'Missing "Authorization" header',
        ]);

        self::assertSame('access_denied', $error->code);
        self::assertNull($error->path);
        self::assertSame('Missing "Authorization" header', $error->details);
    }

    public function testFromArrayIsLenient(): void
    {
        $error = ApiError::fromArray(['message' => 123, 'code' => null, 'path' => '', 'unknown' => ['x'], 'details' => ['not', 'text']]);

        self::assertSame('123', $error->message);
        self::assertNull($error->code);
        self::assertNull($error->path);
        self::assertNull($error->details);
        self::assertEquals(new ApiError(), ApiError::fromArray([]));
    }

    /**
     * @return iterable<string, array{ApiError, string}>
     */
    public static function renderings(): iterable
    {
        yield 'everything' => [new ApiError('Bad carrier data', 'carrierValidationError', '/packages/id/1', 'Street too long'), 'Bad carrier data: Street too long [carrierValidationError] (/packages/id/1)'];
        yield 'message and path' => [new ApiError('Invalid postcode', path: '/receiver/postcode'), 'Invalid postcode (/receiver/postcode)'];
        yield 'code only' => [new ApiError(code: 'unknownError'), 'unknownError'];
        yield 'nothing' => [new ApiError(), 'Unknown error'];
        yield 'details repeat the message' => [new ApiError('Same', details: 'Same'), 'Same'];
        yield 'message and code' => [new ApiError('Denied', 'access_denied'), 'Denied [access_denied]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renderings')]
    public function testItRendersAsOneLine(ApiError $error, string $expected): void
    {
        self::assertSame($expected, (string) $error);
    }
}
