<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DHL: five pallets, the most a shipment can hold',
    shows: 'DHL takes at most 5 pallets in a shipment; the local rules refuse a sixth.',
    carrier: Carrier::Dhl,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: array_fill(0, 5, new Parcel(ParcelType::Pallet, 120, 80, 100, 200.0, 2000.0, 'Machine parts'))),
);
