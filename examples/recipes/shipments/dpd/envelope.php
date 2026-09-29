<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: an envelope',
    shows: 'A document envelope is a parcel of type Dox. It still needs its dimensions (35 x 25 x 2 cm here) and a '
        . 'weight; the value may be zero.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [new Parcel(ParcelType::Dox, 35, 25, 2, 0.5, 0.0, 'Contract')]),
);
