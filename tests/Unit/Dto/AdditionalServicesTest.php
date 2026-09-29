<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;

final class AdditionalServicesTest extends TestCase
{
    public function testNothingSetIsEmptyAndWritesNothing(): void
    {
        $services = new AdditionalServices();

        self::assertTrue($services->isEmpty());
        self::assertSame([], $services->toArray());
    }

    public function testOnlyWhatWasSetIsWritten(): void
    {
        $services = new AdditionalServices(rod: true, saturdayDelivery: true, deliveryOnDay: '2026-10-02');

        self::assertSame(['rod' => true, 'delivery_on_day' => '2026-10-02', 'saturday_delivery' => true], $services->toArray());
        self::assertFalse($services->isEmpty());
    }

    public function testFalseIsAValueAndIsSent(): void
    {
        self::assertSame(['receiver_email_notification' => false], (new AdditionalServices(receiverEmailNotification: false))->toArray());
    }

    public function testCodIsNested(): void
    {
        $services = new AdditionalServices(cod: new Cod(99.5, 'PLN', true, 'PL61109010140000071219812874', 'Jan Kowalski'));

        self::assertSame([
            'cod' => ['amount' => 99.5, 'currency' => 'PLN', 'express' => true, 'iban' => 'PL61109010140000071219812874', 'name' => 'Jan Kowalski'],
        ], $services->toArray());
    }

    public function testEveryTypedFlagIsWrittenUnderItsKey(): void
    {
        $arguments = [];

        foreach ((new \ReflectionClass(AdditionalServices::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = (string) $parameter->getType();
            $arguments[$parameter->getName()] = match (true) {
                $parameter->getName() === 'extra' => [],
                str_contains($type, 'bool') => true,
                str_contains($type, 'string') => 'x',
                str_contains($type, 'Cod') => new Cod(1.0),
                default => self::fail("Unhandled type {$type} of \${$parameter->getName()}."),
            };
        }

        $services = (new \ReflectionClass(AdditionalServices::class))->newInstanceArgs($arguments);
        self::assertInstanceOf(AdditionalServices::class, $services);

        $written = array_keys($services->toArray());
        sort($written);
        $keys = AdditionalServices::KEYS;
        sort($keys);

        self::assertSame($keys, $written);
        self::assertCount(45, $keys);
    }

    public function testTheDeprecatedAndWithdrawnServicesAreNotModelled(): void
    {
        // Deprecated in the specification, or withdrawn by Poczta Polska on 2026-08-24.
        foreach (['declaredvalue', 'guarantee_0800', 'delivery_after_1700', 'check_content', 'delivery_confirmation'] as $key) {
            self::assertNotContains($key, AdditionalServices::KEYS);
        }
    }

    public function testThePocztaGroupHoldsEightServicesThatAreAllTypedFields(): void
    {
        self::assertCount(8, AdditionalServices::POCZTA_EXCLUSIVE);

        foreach (AdditionalServices::POCZTA_EXCLUSIVE as $key) {
            self::assertContains($key, AdditionalServices::KEYS);
        }
    }

    public function testExtraServicesAreAddedAndNullsAreDropped(): void
    {
        $services = new AdditionalServices(rod: true, extra: ['additional_handling_byweight' => true, 'nothing' => null]);

        self::assertSame(['rod' => true, 'additional_handling_byweight' => true], $services->toArray());
    }

    public function testAnExtraThatIsATypedFlagIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"rod" is a typed field, use the parameter instead of $extra');

        new AdditionalServices(extra: ['rod' => true]);
    }
}
