<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DocumentType;
use VeryCodeCom\FurgonetkaApi\Enum\DutyDocumentsOwner;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Enum\Language;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Enum\PackageState;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelState;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;

/**
 * The members of every enum, written out from the OpenAPI specification (snapshot of 2026-09-28)
 * and, for {@see Carrier}, from the live dictionary. A member added or dropped without updating
 * this list is a decision somebody should have made on purpose.
 */
final class EnumsTest extends TestCase
{
    private const EXPECTED = [
        'Carrier' => [Carrier::class, ['dhl', 'dhlinternational', 'dpd', 'fedex', 'gls', 'inpost', 'inpostkurier', 'poczta', 'ruch', 'orlen', 'ups', 'upsaccesspoint', 'xpress', 'meest', 'ambroexpress', 'allegro', 'furgonetka', 'xpressdelivery', 'deligoo', 'pallex', 'swiatprzesylek', 'wza', 'dhlparcel', 'postivo', 'novapost', 'foxpost', 'correos', 'pnt', 'izidrop', 'laposte']],
        'ParcelType' => [ParcelType::class, ['package', 'dox', 'pallet', 'letterprint', 'furniture', 'vehicles', 'machines', 'other']],
        'LabelFileFormat' => [LabelFileFormat::class, ['pdf', 'zpl', 'epl']],
        'LabelPageFormat' => [LabelPageFormat::class, ['a4', 'a6']],
        'DutyType' => [DutyType::class, ['sale', 'gift', 'docs_in_dox']],
        'DutyDocumentsOwner' => [DutyDocumentsOwner::class, ['provided', 'client']],
        'PackageState' => [PackageState::class, ['waiting', 'queued', 'ordered', 'cancelled', 'tracking', 'storage', 'auction']],
        'ParcelState' => [ParcelState::class, ['waiting', 'sent', 'transit', 'delivered', 'canceled', 'collected', 'returned']],
        'TrackingState' => [TrackingState::class, ['waiting', 'ordered', 'collect-problem', 'collected', 'transit', 'delivery', 'delivery-problem', 'delivered', 'unexpected-situation', 'canceled', 'returned', 'ready-for-transport', 'waiting-for-receiver']],
        'ListType' => [ListType::class, ['sent', 'ordered', 'waiting', 'all', 'storage', 'tracking', 'auction']],
        'DocumentType' => [DocumentType::class, ['labels', 'protocols_others', 'labels_and_pickup_confirmations', 'pickup_confirmations', 'tires_instructions', 'all']],
        'AgreementOwner' => [AgreementOwner::class, ['furgonetka', 'client']],
        'PointService' => [PointService::class, ['inpost', 'ruch', 'poczta', 'ups', 'dhl', 'dpd', 'meest', 'fedex', 'orlen', 'furgonetkaPunkt']],
        'PointServiceType' => [PointServiceType::class, ['service_point', 'parcel_machine']],
        'Language' => [Language::class, ['en_GB', 'pl_PL']],
    ];

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, list<string>}>
     */
    public static function enums(): iterable
    {
        foreach (self::EXPECTED as $name => [$class, $values]) {
            yield $name => [$class, $values];
        }
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string>              $values
     */
    #[DataProvider('enums')]
    public function testTheMembersAreTheValuesFurgonetkaDocuments(string $enum, array $values): void
    {
        self::assertSame($values, array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string>              $values
     */
    #[DataProvider('enums')]
    public function testEveryValueRoundTripsThroughTryFrom(string $enum, array $values): void
    {
        foreach ($values as $value) {
            self::assertSame($value, $enum::tryFrom($value)?->value);
        }
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string>              $values
     */
    #[DataProvider('enums')]
    public function testAValueFurgonetkaAddsTomorrowReadsAsNullInsteadOfFailing(string $enum, array $values): void
    {
        self::assertNull($enum::tryFrom('a-value-added-tomorrow'));
        self::assertNull($enum::tryFrom(''));
        self::assertNull($enum::tryFrom(strtoupper($values[0] . 'X')));
    }

    public function testCaseNamesAreReadableWhereTheValueIsNot(): void
    {
        self::assertSame(Carrier::InPost, Carrier::from('inpost'));
        self::assertSame(Carrier::InPostKurier, Carrier::from('inpostkurier'));
        self::assertSame(Carrier::AmbroExpress, Carrier::from('ambroexpress'));
        self::assertSame(\VeryCodeCom\FurgonetkaApi\Enum\TrackingState::CollectProblem, \VeryCodeCom\FurgonetkaApi\Enum\TrackingState::from('collect-problem'));
        self::assertSame(\VeryCodeCom\FurgonetkaApi\Enum\DocumentType::ProtocolsOthers, \VeryCodeCom\FurgonetkaApi\Enum\DocumentType::from('protocols_others'));
    }

    /**
     * The ten values of the specification's `Service` enum and the sixteen of a fresh sandbox
     * account are all members; `furgonetka_gielda`, which no list mentions, is not.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function carriersOfTheTwoLists(): iterable
    {
        foreach (['dpd', 'ups', 'inpost', 'gls', 'fedex', 'orlen', 'poczta', 'dhl', 'meest', 'pallex'] as $value) {
            yield "specification: {$value}" => [$value, true];
        }

        foreach (['ambroexpress', 'deligoo', 'xpressdelivery', 'swiatprzesylek', 'postivo', 'novapost'] as $value) {
            yield "sandbox account: {$value}" => [$value, true];
        }

        yield 'sandbox account, in no list: furgonetka_gielda' => ['furgonetka_gielda', false];
    }

    #[DataProvider('carriersOfTheTwoLists')]
    public function testTheDocumentedCarrierListsAreCovered(string $value, bool $member): void
    {
        self::assertSame($member, Carrier::tryFrom($value) !== null);
    }
}
