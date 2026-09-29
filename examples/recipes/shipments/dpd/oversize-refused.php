<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a parcel that is too long is refused',
    shows: 'A 200 cm long parcel breaks the carrier\'s dimensional weight and the limit of the shorter sides, and the '
        . 'validation says so per field (packageDimensionalWeightExceeded, shorterSidesDimensionsExceeded).',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(200, 40, 40, 10.0, 100.0, 'Pipe')]),
    refusedWith: ['packageDimensionalWeightExceeded', 'shorterSidesDimensionsExceeded'],
);
