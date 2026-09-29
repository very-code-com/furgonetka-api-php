<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'FedEx: two parcels',
    shows: 'Several parcels in one shipment: FedEx takes them, each with its own size, weight and value.',
    carrier: Carrier::Fedex,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Scenario::parcel(), Scenario::parcel()]),
);
