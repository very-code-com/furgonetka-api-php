<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Pickup;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;

/**
 * Builders for shipments that are valid as they come, so a test changes only the one thing it is
 * about. The defaults are the payload that the sandbox validated with 204 for DPD on 2026-09-29.
 */
final class ShipmentFactory
{
    /** The id of the DPD service on the sandbox account of this project. */
    public const SERVICE_ID = 12014776;

    public static function pickup(
        ?string $name = 'Jan Kowalski',
        ?string $company = null,
        string $street = 'Marszalkowska 100',
        string $postcode = '00-026',
        string $city = 'Warszawa',
        ?string $country = 'PL',
        ?string $county = null,
        ?string $point = null,
    ): Pickup {
        return new Pickup($street, $postcode, $city, $name, $company, $country, $county, 'jan@example.com', '500600700', $point);
    }

    public static function receiver(
        ?string $name = 'Anna Nowak',
        ?string $company = null,
        ?string $street = 'Florianska 15',
        ?string $postcode = '31-021',
        ?string $city = 'Krakow',
        ?string $country = 'PL',
        ?string $county = null,
        ?string $point = null,
    ): Receiver {
        return new Receiver($name, $company, $street, $postcode, $city, $country, $county, 'anna@example.com', '600700800', $point);
    }

    public static function labelSender(
        ?string $name = 'Piotr Zielinski',
        ?string $company = null,
        string $street = 'Prosta 1',
        string $postcode = '00-838',
        string $city = 'Warszawa',
        ?string $country = null,
        ?string $county = null,
    ): LabelSender {
        return new LabelSender($street, $postcode, $city, $name, $company, $country, $county, 's@example.com', '500100200');
    }

    public static function parcel(
        ParcelType $type = ParcelType::Package,
        ?float $value = 100.0,
        ?string $description = 'Ksiazki',
        ?int $quantity = null,
        ?string $brand = null,
        ?string $model = null,
    ): Parcel {
        return new Parcel($type, 30, 40, 20, 5.0, $value, $description, $quantity, $brand, $model);
    }

    /**
     * @param list<Parcel>|null $parcels
     * @param list<string>      $photos
     */
    public static function request(
        int $serviceId = self::SERVICE_ID,
        ?Pickup $pickup = null,
        ?Receiver $receiver = null,
        ?array $parcels = null,
        ?string $reference = null,
        ?LabelSender $labelSender = null,
        ?AdditionalServices $services = null,
        ?Duty $duty = null,
        array $photos = [],
    ): ShipmentRequest {
        return new ShipmentRequest(
            serviceId: $serviceId,
            pickup: $pickup ?? self::pickup(),
            receiver: $receiver ?? self::receiver(),
            parcels: $parcels ?? [self::parcel()],
            userReferenceNumber: $reference,
            labelSender: $labelSender,
            additionalServices: $services,
            duty: $duty,
            photos: $photos,
        );
    }
}
