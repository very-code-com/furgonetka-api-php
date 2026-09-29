<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: dropped off at a Pickup point',
    shows: 'The sender leaves the parcel at a point instead of waiting for a courier: Pickup::point carries the '
        . 'point, and the street, postcode and city are the point\'s.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(pickup: $s->pointPickup(PointService::Dpd, 'Warszawa, Jerozolimskie 29')),
);
