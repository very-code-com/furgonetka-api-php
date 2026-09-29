<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Examples;

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\ExportItem;
use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Payer;
use VeryCodeCom\FurgonetkaApi\Dto\Pickup;
use VeryCodeCom\FurgonetkaApi\Dto\Point;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Dto\ServiceList;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

/**
 * The starting point of a shipment recipe: a working domestic shipment (Warsaw to Krakow, one 5 kg parcel) on the
 * account's contract with one carrier, of which a recipe changes the one thing it is about.
 *
 *   $s->request(services: new AdditionalServices(cod: new Cod(100.0)))
 *
 * The addresses and the phone numbers are made up. Replace them before you use a recipe for a real shipment.
 */
final class Scenario
{
    public function __construct(
        public readonly FurgonetkaApiClient $client,
        public readonly Carrier $carrier,
    ) {
    }

    /**
     * The id of the account's contract with the carrier: Furgonetka's own, else the first one.
     *
     * @throws \RuntimeException when the account has no contract with the carrier.
     */
    public function serviceId(): int
    {
        $services = $this->services();
        $service = $services->first($this->carrier, AgreementOwner::Furgonetka) ?? $services->first($this->carrier);

        if ($service === null || $service->id === null) {
            throw new \RuntimeException("The account has no contract with {$this->carrier->value}.");
        }

        return $service->id;
    }

    /** The account's contracts, asked for once per client: a full run of the cookbook would otherwise ask for them a hundred times. */
    private function services(): ServiceList
    {
        /** @var \WeakMap<FurgonetkaApiClient, ServiceList>|null $cache */
        static $cache = null;
        $cache ??= new \WeakMap();

        return $cache[$this->client] ??= $this->client->listServices();
    }

    /**
     * The first pickup point of a carrier near a place, found the way an application would: by asking.
     *
     * @throws \RuntimeException when there is none.
     */
    public function point(PointService $service, string $near): Point
    {
        $point = $this->client->findPoints(new PointsQuery(services: [$service], searchPhrase: $near, limit: 5))->first();

        if ($point === null || $point->identifier() === null) {
            throw new \RuntimeException("No {$service->value} point was found near \"{$near}\".");
        }

        return $point;
    }

    /** A receiver who collects the parcel at a point of a carrier. */
    public function pointReceiver(PointService $service, string $near): Receiver
    {
        return new Receiver(name: 'Anna Nowak', countryCode: 'PL', email: 'anna@example.com', phone: '600700800', point: $this->point($service, $near)->identifier());
    }

    /**
     * A sender who leaves the parcel at a point of a carrier; the address is the point's. Some carriers (ORLEN) give
     * points without a postcode, which the sender's address needs: pass it then.
     */
    public function pointPickup(PointService $service, string $near, ?string $postcode = null): Pickup
    {
        $point = $this->point($service, $near);

        return new Pickup(
            (string) $point->address?->street,
            $postcode ?? (string) $point->address?->postcode,
            (string) $point->address?->city,
            name: 'Jan Kowalski',
            countryCode: 'PL',
            email: 'jan@example.com',
            phone: '500600700',
            point: $point->identifier(),
        );
    }

    /**
     * A shipment with the defaults, and whatever the recipe gives instead of them.
     *
     * @param list<Parcel>|null $parcels
     */
    public function request(
        ?Pickup $pickup = null,
        ?Receiver $receiver = null,
        ?array $parcels = null,
        ?AdditionalServices $services = null,
        ?LabelSender $labelSender = null,
        ?Duty $duty = null,
        ?Payer $payer = null,
        ?string $reference = null,
    ): ShipmentRequest {
        return new ShipmentRequest(
            serviceId: $this->serviceId(),
            pickup: $pickup ?? self::pickup(),
            receiver: $receiver ?? self::receiver(),
            parcels: $parcels ?? [self::parcel()],
            userReferenceNumber: $reference,
            labelSender: $labelSender,
            additionalServices: $services,
            duty: $duty,
            payer: $payer,
        );
    }

    /** Cash on delivery with an example account of the sender; some carriers pay the amount out to it. */
    public static function cashOnDelivery(float $amount): Cod
    {
        return new Cod($amount, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski');
    }

    /** A line of a customs declaration: the paperback books of the default parcel, described in Polish and English. */
    public static function customsItem(): ExportItem
    {
        return new ExportItem('Printed paperback books', 'Printed paperback books', 'Paper and card board', 'Paper and card board', 'Reading by a private person', 'Reading by a private person', 1, 100.0, 'PL', 'FV/1/2026', 5.0, '4901990000');
    }

    /**
     * The newest shipment of the account that is really ordered (the "ordered" list also holds cancelled ones) and has
     * a waybill number.
     *
     * @throws \RuntimeException when there is none.
     */
    public static function latestOrdered(FurgonetkaApiClient $client): Package
    {
        foreach ($client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Ordered)) as $package) {
            if ($package->isOrdered() && $package->waybillNumbers() !== []) {
                return $package;
            }
        }

        throw new \RuntimeException('The account has no ordered shipment with a waybill number.');
    }

    /** One line about a point: code, kind, name, address, distance, whether it takes cash on delivery. */
    public static function pointLine(Point $point): string
    {
        return sprintf(
            '%-16s %-12s %-30s %s, %s%s%s',
            (string) $point->identifier(),
            (string) ($point->type ?? $point->serviceValue),
            mb_substr((string) $point->name, 0, 30),
            (string) $point->address?->street,
            (string) $point->address?->city,
            $point->distance !== null ? sprintf('  %.2f km', $point->distance) : '',
            $point->cod === true ? '  COD' : '',
        );
    }

    /** One line about a shipment: id, carrier, state, waybill numbers, reference. */
    public static function packageLine(Package $package): string
    {
        return sprintf(
            '%-9s %-10s %-10s %-22s %s',
            (string) $package->id,
            (string) ($package->carrier->value ?? $package->carrierName),
            (string) ($package->state->value ?? $package->stateValue),
            implode(',', $package->waybillNumbers()) ?: '-',
            (string) $package->userReferenceNumber,
        );
    }

    /** The sender: a person in Warsaw. */
    public static function pickup(): Pickup
    {
        return new Pickup('Marszalkowska 100', '00-026', 'Warszawa', name: 'Jan Kowalski', countryCode: 'PL', email: 'jan@example.com', phone: '500600700');
    }

    /** The receiver: a person in Krakow. */
    public static function receiver(): Receiver
    {
        return new Receiver(name: 'Anna Nowak', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'anna@example.com', phone: '600700800');
    }

    /** A 30 x 40 x 20 cm parcel of 5 kg worth 100 PLN. */
    public static function parcel(): Parcel
    {
        return Parcel::package(30, 40, 20, 5.0, 100.0, 'Books');
    }
}
