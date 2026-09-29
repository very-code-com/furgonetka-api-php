<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * A shipment to create, validate or price (`PackageParamsV2`).
 *
 * One DTO for all three, because Furgonetka uses one schema: `createPackage()`,
 * `validateRemotely()` and, wrapped with a {@see PriceScope}, `calculatePrices()` take the same
 * payload. Build it once and use it as often as you need.
 *
 *   $request = new ShipmentRequest(
 *       serviceId: $dpd->id,
 *       pickup: new Pickup(name: 'Jan Kowalski', street: 'Marszalkowska 100', postcode: '00-026', city: 'Warszawa', phone: '500600700'),
 *       receiver: new Receiver(name: 'Anna Nowak', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', phone: '600700800'),
 *       parcels: [Parcel::package(width: 30, depth: 40, height: 20, weight: 5.0)],
 *   );
 *
 * Only version 2 of the operation is modelled: the type of the shipment lives on each parcel, and
 * the version 1 `type` and `label.file_format` of the shipment are not sent. The `auction` and
 * `tires` structures (the freight exchange and tire shipping) are out of scope.
 *
 * Every optional field is nullable on purpose: null leaves it out and lets Furgonetka's default
 * apply.
 *
 * `$extra` is the way out when Furgonetka adds a field before this release models it. It is your
 * responsibility: nothing in it is checked locally, and a name that is also a typed field is
 * refused, so there is one way to set each field.
 */
final class ShipmentRequest
{
    /** JSON keys of the typed fields, to keep `$extra` from shadowing them. */
    public const KEYS = [
        'service_id',
        'pickup',
        'receiver',
        'parcels',
        'user_reference_number',
        'sender',
        'additional_services',
        'duty',
        'payer',
        'files_uuids',
        'photos',
    ];

    /**
     * @param int                    $serviceId           Your contract with the carrier: `id` from `GET /account/services`.
     * @param Pickup                 $pickup              Where the courier collects (or the sender's data for a point).
     * @param Receiver               $receiver            Where the shipment is delivered; a `point` replaces the address for InPost parcel lockers and ORLEN Paczka.
     * @param list<Parcel>           $parcels             The parcels, at least one.
     * @param string|null            $userReferenceNumber Your document number, printed on the label. The documentation limits it for
     *                                                    DPD (27), FedEx (30), UPS (35), InPost (28) and GLS (25); the sandbox accepted
     *                                                    more, see the validator.
     * @param LabelSender|null       $labelSender         Other data on the label; sent as `sender`. Returns go there.
     * @param AdditionalServices|null $additionalServices Additional services.
     * @param Duty|null              $duty                Customs data, for the destinations that need it.
     * @param Payer|null             $payer               Financial data for the carrier (cost centre).
     * @param list<string>           $filesUuids          UUIDs of uploaded files, for instance documents to print (Postivo).
     * @param list<string>           $photos              The `file_uuid` of photos uploaded with `uploadFile(..., type: 'packagePhoto')`, one to three; the UUID of the upload command is refused.
     * @param array<string, mixed>   $extra               Fields this release does not model, by their API name. Nulls are left out.
     */
    public function __construct(
        public readonly int $serviceId,
        public readonly Pickup $pickup,
        public readonly Receiver $receiver,
        public readonly array $parcels,
        public readonly ?string $userReferenceNumber = null,
        public readonly ?LabelSender $labelSender = null,
        public readonly ?AdditionalServices $additionalServices = null,
        public readonly ?Duty $duty = null,
        public readonly ?Payer $payer = null,
        public readonly array $filesUuids = [],
        public readonly array $photos = [],
        public readonly array $extra = [],
    ) {
        $shadowed = array_values(array_intersect(array_keys($extra), self::KEYS));

        if ($shadowed !== []) {
            throw new \InvalidArgumentException(sprintf(
                'ShipmentRequest: "%s" is a typed field, use the parameter instead of $extra.',
                $shadowed[0],
            ));
        }
    }

    /**
     * The request body: only what was set, then `$extra`. `parcels` is always there, even when
     * empty, so that Furgonetka's "at least one parcel" message is what an empty shipment gets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $services = $this->additionalServices?->toArray();

        return [
            ...Payload::compact([
                'service_id' => $this->serviceId,
                'pickup' => $this->pickup->toArray(),
                'receiver' => $this->receiver->toArray(),
                'user_reference_number' => $this->userReferenceNumber,
                'sender' => $this->labelSender?->toArray(),
                'additional_services' => $services,
                'duty' => $this->duty?->toArray(),
                'payer' => $this->payer?->toArray(),
                'files_uuids' => $this->filesUuids,
                'photos' => $this->photos,
            ]),
            'parcels' => array_map(static fn (Parcel $parcel): array => $parcel->toArray(), $this->parcels),
            ...Payload::compact($this->extra),
        ];
    }
}
