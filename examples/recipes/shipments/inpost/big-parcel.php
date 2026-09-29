<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: a heavier courier parcel',
    shows: 'A 60 x 40 x 30 cm parcel of 20 kg goes by courier. A locker has smaller limits (see '
        . 'locker-oversize-refused).',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(60, 40, 30, 20.0, 100.0, 'Heavy')]),
);
