<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: a parcel of 30 kg',
    shows: 'One heavy parcel is fine; UPS on Furgonetka\'s contract takes one parcel per shipment (see the next '
        . 'recipe).',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(60, 40, 40, 30.0, 300.0, 'Tools')]),
);
