<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'GLS: fifteen parcels worth 100 PLN each are too much',
    shows: 'GLS limits the total value of a shipment: fifteen parcels of 100 PLN are answered with sumValueTooHigh '
        . 'on each parcel. The count itself (15) is allowed.',
    carrier: Carrier::Gls,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: array_fill(0, 15, Scenario::parcel())),
    refusedWith: ['sumValueTooHigh'],
);
