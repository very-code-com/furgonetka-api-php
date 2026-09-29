<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Env;

final class EnvTest extends TestCase
{
    private const NAME = 'FURGONETKA_ENV_TEST_VARIABLE';

    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    private function clear(): void
    {
        putenv(self::NAME);
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
    }

    public function testAnUnsetVariableReadsAsEmpty(): void
    {
        self::assertSame('', Env::read(self::NAME));
    }

    public function testEachSourceIsRead(): void
    {
        putenv(self::NAME . '=from getenv');
        self::assertSame('from getenv', Env::read(self::NAME));

        $this->clear();
        $_ENV[self::NAME] = 'from $_ENV';
        self::assertSame('from $_ENV', Env::read(self::NAME));

        $this->clear();
        $_SERVER[self::NAME] = 'from $_SERVER';
        self::assertSame('from $_SERVER', Env::read(self::NAME));
    }

    public function testTheSourcesAreTriedInOrderAndBlankValuesAreSkipped(): void
    {
        putenv(self::NAME . '=  ');
        $_ENV[self::NAME] = 'second';
        $_SERVER[self::NAME] = 'third';

        self::assertSame('second', Env::read(self::NAME));
    }

    public function testTheValueIsTrimmed(): void
    {
        putenv(self::NAME . '=  padded  ');

        self::assertSame('padded', Env::read(self::NAME));
    }

    public function testANonStringServerValueIsIgnored(): void
    {
        $_SERVER[self::NAME] = ['not', 'a', 'string'];

        self::assertSame('', Env::read(self::NAME));
    }
}
