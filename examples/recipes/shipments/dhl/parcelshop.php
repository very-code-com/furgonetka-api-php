<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DHL: to a ParcelShop',
    shows: 'A point is found with findPoints() and its code goes into Receiver::point.',
    carrier: Carrier::Dhl,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: $s->pointReceiver(PointService::Dhl, 'Warszawa, Jerozolimskie 50')),
);
