<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;

final class FurgonetkaApiExceptionTest extends TestCase
{
    public function testDebugReportIncludesTheRawResponseAndTheCause(): void
    {
        $cause = new \RuntimeException('underlying');
        $exception = (new FurgonetkaApiException('failed', 0, $cause))
            ->withRawResponse('{"errors":[{"path":"/receiver/postcode","message":"invalid"}]}');

        $report = $exception->getDebugReport();

        self::assertStringContainsString('FurgonetkaApiException: failed', $report);
        self::assertStringContainsString('--- Raw Furgonetka response ---', $report);
        self::assertStringContainsString('/receiver/postcode', $report);
        self::assertStringContainsString('--- Caused by ---', $report);
        self::assertStringContainsString('underlying', $report);
        self::assertStringContainsString('--- Stack trace ---', $report);
    }

    public function testDebugReportOmitsSectionsThatHaveNoContent(): void
    {
        $report = (new FurgonetkaApiException('timeout'))->getDebugReport();

        self::assertStringNotContainsString('--- Raw Furgonetka response ---', $report);
        self::assertStringNotContainsString('--- Caused by ---', $report);
    }

    public function testAnEmptyRawResponseIsLeftOutOfTheReport(): void
    {
        $report = (new FurgonetkaApiException('no body'))->withRawResponse('')->getDebugReport();

        self::assertStringNotContainsString('--- Raw Furgonetka response ---', $report);
    }

    public function testWithRawResponseIsFluentAndKeepsTheConcreteType(): void
    {
        $exception = new FurgonetkaApiException('nope');

        self::assertSame($exception, $exception->withRawResponse('{}'));
        self::assertSame('{}', $exception->getRawResponse());
    }
}
