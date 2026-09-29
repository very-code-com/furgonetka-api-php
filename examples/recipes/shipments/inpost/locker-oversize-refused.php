<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: a parcel too big for a locker is refused',
    shows: 'A 100 x 60 x 50 cm parcel does not fit a locker: the API answers packageDimensionExceededDomestic on '
        . 'each dimension. Send it by courier instead.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: $s->pointReceiver(PointService::Inpost, 'Warszawa, Towarowa 20'), parcels: [Parcel::package(100, 60, 50, 10.0, 100.0, 'Big')]),
    refusedWith: ['packageDimensionExceededDomestic'],
);
