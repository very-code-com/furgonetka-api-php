<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DHL: delivery at a chosen time is not supported',
    shows: 'deliveryOnTime takes a time such as 12:00 where a carrier offers it; DHL answers notSupported.',
    carrier: Carrier::Dhl,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(deliveryOnTime: '12:00')),
    refusedWith: ['notSupported'],
);
