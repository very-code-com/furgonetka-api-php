<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: a digital label is not supported',
    shows: 'digitalLabel is answered with notSupported even for a locker.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: $s->pointReceiver(PointService::Inpost, 'Warszawa, Towarowa 20'), services: new AdditionalServices(digitalLabel: true)),
    refusedWith: ['notSupported'],
);
