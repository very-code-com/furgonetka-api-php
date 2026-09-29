<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth\Store;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\Store\InMemoryTokenStore;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class InMemoryTokenStoreTest extends TestCase
{
    public function testItStartsEmptyAndKeepsTheLastSavedToken(): void
    {
        $store = new InMemoryTokenStore();
        self::assertNull($store->load());

        $first = TokenFactory::token('access-1');
        $second = TokenFactory::token('access-2');

        $store->save($first);
        self::assertSame($first, $store->load());

        $store->save($second);
        self::assertSame($second, $store->load());
    }

    public function testItCanStartWithAToken(): void
    {
        $token = TokenFactory::token();

        self::assertSame($token, (new InMemoryTokenStore($token))->load());
    }

    public function testSynchronizedRunsTheCallbackAndReturnsItsResult(): void
    {
        $store = new InMemoryTokenStore();

        self::assertSame(42, $store->synchronized(static fn (): int => 42));
    }

    public function testSynchronizedLetsExceptionsThrough(): void
    {
        $this->expectException(\DomainException::class);

        (new InMemoryTokenStore())->synchronized(static function (): never {
            throw new \DomainException('from the callback');
        });
    }
}
