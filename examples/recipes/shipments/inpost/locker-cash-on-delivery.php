<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: cash on delivery to a parcel locker',
    shows: 'A COD parcel can go to a locker (the receiver pays at the locker). The point must accept COD; most '
        . 'InPost points do.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: $s->pointReceiver(PointService::Inpost, 'Warszawa, Towarowa 20'), services: new AdditionalServices(cod: Scenario::cashOnDelivery(100.0))),
);
