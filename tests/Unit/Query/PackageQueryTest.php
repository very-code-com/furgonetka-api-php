<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Query\PackageFilters;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

/**
 * What the list query turns into. The key names are Furgonetka's; `services` was checked live
 * (a filter under the key `service` is silently ignored, `services` filters).
 */
final class PackageQueryTest extends TestCase
{
    public function testAQueryWithNothingSetIsEmpty(): void
    {
        self::assertSame([], (new PackageListQuery())->toArray());
        self::assertSame([], (new PackageFilters())->toArray());
    }

    public function testEveryFieldOfTheQueryHasItsKey(): void
    {
        $query = new PackageListQuery(limit: 20, lastPackageId: 21353373, listType: ListType::Sent, query: 'Kowalski', filters: new PackageFilters(withCod: false));

        self::assertSame(
            ['limit' => 20, 'last_package_id' => '21353373', 'list_type' => 'sent', 'query' => 'Kowalski', 'filters' => ['with_cod' => false]],
            $query->toArray(),
            'A false filter is a filter; only null and empty lists are left out.',
        );
    }

    public function testTheLastPackageIdIsAStringWhateverItCameAs(): void
    {
        self::assertSame('7', (new PackageListQuery(lastPackageId: 7))->toArray()['last_package_id']);
        self::assertSame('7', (new PackageListQuery(lastPackageId: '7'))->toArray()['last_package_id']);
    }

    public function testALimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be at least 1');

        new PackageListQuery(limit: 0);
    }

    public function testEveryFilterHasItsKey(): void
    {
        $filters = new PackageFilters(
            services: [Carrier::Dpd, 'gls'],
            interval: 'last_30_days',
            dateFrom: '2026-09-01',
            dateTo: new \DateTimeImmutable('2026-09-29 18:30'),
            packageTypes: ['package', 'pallet'],
            relations: ['domestic'],
            statuses: [TrackingState::CollectProblem, 'delivered'],
            withCod: true,
            codStatuses: ['paid'],
            orderTypes: ['manual'],
            packagesIds: [1, '2'],
            groupsIds: [3],
            groups: 'only_grouped',
            serviceContract: ['provided'],
        );

        self::assertSame(
            [
                'services' => ['dpd', 'gls'],
                'interval' => 'last_30_days',
                'date' => ['from' => '2026-09-01', 'to' => '2026-09-29'],
                'package_types' => ['package', 'pallet'],
                'relations' => ['domestic'],
                'statuses' => ['collect-problem', 'delivered'],
                'with_cod' => true,
                'cod_statuses' => ['paid'],
                'order_types' => ['manual'],
                'packages_ids' => ['1', '2'],
                'groups_ids' => ['3'],
                'groups' => 'only_grouped',
                'service_contract' => ['provided'],
            ],
            $filters->toArray(),
        );
    }

    public function testADateRangeMayBeOpenOnOneSide(): void
    {
        self::assertSame(['date' => ['from' => '2026-09-01']], (new PackageFilters(dateFrom: '2026-09-01'))->toArray());
        self::assertSame(['date' => ['to' => '2026-09-30']], (new PackageFilters(dateTo: '2026-09-30'))->toArray());
    }
}
