<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: two parcels in one shipment are refused',
    shows: 'On Furgonetka\'s UPS contract a shipment holds one parcel: the API answers notLessThanInclusive on '
        . '/parcels/0, and the local rules know it. Make two shipments.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Scenario::parcel(), Scenario::parcel()]),
    refusedWith: ['notLessThanInclusive'],
);
