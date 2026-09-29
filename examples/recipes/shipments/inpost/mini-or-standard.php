<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: the mini or standard size',
    shows: 'miniOrStandard is a flag on AdditionalServices, accepted for a locker delivery.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: $s->pointReceiver(PointService::Inpost, 'Warszawa, Towarowa 20'), services: new AdditionalServices(miniOrStandard: true)),
);
