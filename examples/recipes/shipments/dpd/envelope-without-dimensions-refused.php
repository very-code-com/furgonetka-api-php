<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: an envelope without dimensions is refused',
    shows: 'Leaving the dimensions out of a Dox parcel is answered with isEmpty on the width, the depth and the '
        . 'height. Only some parcel types have defaults, and Dox is not one of them.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [new Parcel(ParcelType::Dox, null, null, null, 0.5, 0.0, 'Contract')]),
    refusedWith: ['isEmpty'],
);
