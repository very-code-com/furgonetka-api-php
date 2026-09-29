<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'AmbroExpress: two parcels',
    shows: 'Several parcels in one shipment: AmbroExpress takes them and prices them together.',
    carrier: Carrier::AmbroExpress,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Scenario::parcel(), Scenario::parcel()]),
);
