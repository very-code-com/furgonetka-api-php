<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: dropped off at a parcel locker',
    shows: 'The sender leaves the parcel at a locker instead of waiting for a courier: Pickup::point carries the '
        . 'point, and the address is the point\'s. Here both ends are lockers.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(pickup: $s->pointPickup(PointService::Inpost, 'Warszawa, Towarowa 20'), receiver: $s->pointReceiver(PointService::Inpost, 'Warszawa, Marszalkowska 94')),
);
