<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: the UPS notification flags are not supported',
    shows: 'upsShipNotification, upsDeliveryNotification and upsExceptionNotification are answered with notSupported '
        . 'on this contract.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(upsShipNotification: true, upsDeliveryNotification: true, upsExceptionNotification: true)),
    refusedWith: ['notSupported'],
);
