<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: delivery on a chosen day is not supported',
    shows: 'deliveryOnDay takes a date (YYYY-MM-DD) where a carrier supports it; DPD answers notSupported.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(deliveryOnDay: date('Y-m-d', strtotime('+3 weekdays')))),
    refusedWith: ['notSupported'],
);
