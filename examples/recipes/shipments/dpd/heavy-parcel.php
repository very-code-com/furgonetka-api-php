<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a parcel of 31.5 kg',
    shows: 'A heavy parcel is not refused by the validation. The carrier applies its own limits when it handles the '
        . 'parcel, so check its terms for anything near or above 31.5 kg.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(60, 40, 40, 31.5, 300.0, 'Tools')]),
);
