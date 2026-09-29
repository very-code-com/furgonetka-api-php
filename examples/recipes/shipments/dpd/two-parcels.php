<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: two parcels of different sizes',
    shows: 'One shipment can hold several parcels, each with its own size, weight, value and description. The '
        . 'carrier prices them together and gives every parcel its own waybill number.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Scenario::parcel(), Parcel::package(20, 20, 10, 1.5, 50.0, 'Cables')]),
);
