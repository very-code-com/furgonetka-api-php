<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A pickup point: a parcel locker or a staffed point where a shipment is handed in or collected.
 *
 * **The identifier is `code`.** The specification names it `point_id`, but the sandbox sends only
 * `code` ("WAW099", "PL24557", "FR_T-PM-03"), and that is the value a shipment takes in
 * `Receiver::$point`. {@see self::identifier()} returns whichever of the two is there.
 *
 * `distance` is kilometres from the centre of the search. It can be null: the sandbox left it null
 * on the first point of a phrase search. `opening_hours` is null for a locker that is open all the time. `service` and
 * `serviceType` are enums read with `tryFrom()`; a value added after this release reads as null and
 * stays in `serviceValue` and `serviceTypeValue`. `type` and `pointTypeName` are the carrier's own
 * labels ("PACZKOMAT", "APM", "dpd_standard") and vary between carriers.
 */
final class Point
{
    /**
     * @param string|null               $pointId               `point_id` of the specification; the sandbox does not send it.
     * @param string|null               $code                  The point's identifier.
     * @param string|null               $name                  Name.
     * @param string|null               $originalName          The carrier's name for it.
     * @param bool|null                 $active                False when the point is not available at the moment.
     * @param OpeningHours|null         $openingHours          Weekly hours; null when there are none.
     * @param float|null                $maxSupportedWeight    Heaviest shipment it takes, kg.
     * @param bool|null                 $cod                   Whether it handles cash on delivery.
     * @param string|null               $type                  The carrier's label of the point kind.
     * @param PointService|null         $service               Carrier; null when it is not a member.
     * @param string|null               $serviceValue          Carrier as sent.
     * @param bool|null                 $isSendPoint           Whether a shipment can be handed in here.
     * @param bool|null                 $isDeliveryPoint       Whether a shipment can be collected here.
     * @param string|null               $description           Where exactly it is.
     * @param Coordinates|null          $coordinates           Where it is on the map.
     * @param float|null                $distance              Kilometres from the centre of the search.
     * @param PointAddress|null         $address               Address.
     * @param string|null               $phone                 Phone.
     * @param list<string>              $photos                Photo addresses.
     * @param string|null               $pointTypeName         Name of the point kind.
     * @param string|null               $email                 E-mail.
     * @param list<BoxSpecification>|null $boxes               Compartment sizes of a locker.
     * @param bool|null                 $holiday               True while the point is on holiday.
     * @param PointServiceType|null     $serviceType           Locker or staffed point; null when it is not a member.
     * @param string|null               $serviceTypeValue      Locker or staffed point as sent.
     * @param \DateTimeImmutable|null   $holidayStart          First day of the holiday.
     * @param \DateTimeImmutable|null   $holidayEnd            Last day of the holiday.
     * @param bool|null                 $furgonetkaPoint       Whether it is a Furgonetka franchise point.
     * @param string|null               $facebookUrl           Facebook page.
     * @param string|null               $googleUrl             Google business page.
     * @param bool|null                 $digitalLabel          Whether it takes a shipment without a printed label.
     * @param bool|null                 $food                  Whether it handles the Food service.
     * @param bool|null                 $isPocztaPointsPartner Whether it is a Poczta Polska partner point.
     * @param string|null               $label                 Label of the point.
     * @param string|null               $originalPointId       The carrier's own id, for a Furgonetka point.
     * @param array<string, mixed>      $raw                   The whole object as Furgonetka sent it.
     */
    public function __construct(
        public readonly ?string $pointId = null,
        public readonly ?string $code = null,
        public readonly ?string $name = null,
        public readonly ?string $originalName = null,
        public readonly ?bool $active = null,
        public readonly ?OpeningHours $openingHours = null,
        public readonly ?float $maxSupportedWeight = null,
        public readonly ?bool $cod = null,
        public readonly ?string $type = null,
        public readonly ?PointService $service = null,
        public readonly ?string $serviceValue = null,
        public readonly ?bool $isSendPoint = null,
        public readonly ?bool $isDeliveryPoint = null,
        public readonly ?string $description = null,
        public readonly ?Coordinates $coordinates = null,
        public readonly ?float $distance = null,
        public readonly ?PointAddress $address = null,
        public readonly ?string $phone = null,
        public readonly array $photos = [],
        public readonly ?string $pointTypeName = null,
        public readonly ?string $email = null,
        public readonly ?array $boxes = null,
        public readonly ?bool $holiday = null,
        public readonly ?PointServiceType $serviceType = null,
        public readonly ?string $serviceTypeValue = null,
        public readonly ?\DateTimeImmutable $holidayStart = null,
        public readonly ?\DateTimeImmutable $holidayEnd = null,
        public readonly ?bool $furgonetkaPoint = null,
        public readonly ?string $facebookUrl = null,
        public readonly ?string $googleUrl = null,
        public readonly ?bool $digitalLabel = null,
        public readonly ?bool $food = null,
        public readonly ?bool $isPocztaPointsPartner = null,
        public readonly ?string $label = null,
        public readonly ?string $originalPointId = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $hours = Value::object($data, 'opening_hours');
        $coordinates = Value::object($data, 'coordinates');
        $address = Value::object($data, 'address');
        $holiday = Value::object($data, 'holiday_period') ?? [];
        $boxes = Value::objectList($data, 'boxes_specification');

        return new self(
            pointId: Value::string($data, 'point_id'),
            code: Value::string($data, 'code'),
            name: Value::string($data, 'name'),
            originalName: Value::string($data, 'original_name'),
            active: Value::bool($data, 'active'),
            openingHours: $hours !== null ? OpeningHours::fromArray($hours) : null,
            maxSupportedWeight: Value::float($data, 'max_supported_weight'),
            cod: Value::bool($data, 'cod'),
            type: Value::string($data, 'type'),
            service: Value::enum($data, 'service', PointService::class),
            serviceValue: Value::string($data, 'service'),
            isSendPoint: Value::bool($data, 'is_send_point'),
            isDeliveryPoint: Value::bool($data, 'is_delivery_point'),
            description: Value::string($data, 'description'),
            coordinates: $coordinates !== null ? Coordinates::fromArray($coordinates) : null,
            distance: Value::float($data, 'distance'),
            address: $address !== null ? PointAddress::fromArray($address) : null,
            phone: Value::string($data, 'phone'),
            photos: Value::stringList($data, 'photos'),
            pointTypeName: Value::string($data, 'point_type_str'),
            email: Value::string($data, 'email'),
            boxes: $boxes !== [] ? array_map(BoxSpecification::fromArray(...), $boxes) : null,
            holiday: Value::bool($data, 'holiday'),
            serviceType: Value::enum($data, 'service_type', PointServiceType::class),
            serviceTypeValue: Value::string($data, 'service_type'),
            holidayStart: Value::dateTime($holiday, 'start_date'),
            holidayEnd: Value::dateTime($holiday, 'end_date'),
            furgonetkaPoint: Value::bool($data, 'furgonetka_point'),
            facebookUrl: Value::string($data, 'facebook_url'),
            googleUrl: Value::string($data, 'google_url'),
            digitalLabel: Value::bool($data, 'digital_label'),
            food: Value::bool($data, 'food'),
            isPocztaPointsPartner: Value::bool($data, 'is_poczta_points_partner'),
            label: Value::string($data, 'label'),
            originalPointId: Value::string($data, 'original_point_id'),
            raw: $data,
        );
    }

    /** The identifier to give a shipment: `point_id` when Furgonetka sends it, else `code`. */
    public function identifier(): ?string
    {
        return $this->pointId ?? $this->code;
    }

    /**
     * The whole object as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
