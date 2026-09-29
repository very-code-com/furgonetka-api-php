<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Auth\TokenProviderInterface;

final class StaticTokenProviderTest extends TestCase
{
    /** 15 characters, so that a leak into a stack trace (which cuts arguments to 15) would show whole. */
    private const TOKEN = 'static-token-15';

    public function testItHandsOutTheTokenItWasGiven(): void
    {
        $provider = new StaticTokenProvider(self::TOKEN);

        self::assertInstanceOf(TokenProviderInterface::class, $provider);
        self::assertSame(self::TOKEN, $provider->accessToken());
    }

    public function testInvalidateChangesNothingBecauseThereIsNoOtherToken(): void
    {
        $provider = new StaticTokenProvider(self::TOKEN);

        $provider->invalidate();

        self::assertSame(self::TOKEN, $provider->accessToken());
    }

    public function testAnEmptyTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StaticTokenProvider('  ');
    }

    public function testTheTokenDoesNotAppearInDumps(): void
    {
        $provider = new StaticTokenProvider(self::TOKEN);

        ob_start();
        var_dump($provider);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString(self::TOKEN, print_r($provider, true));
        self::assertStringContainsString('[redacted]', $dump);
    }
}
