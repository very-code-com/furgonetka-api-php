<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'GLS: cash on delivery is not supported',
    shows: 'GLS answers notSupported on /additional_services/cod, although the contract list says COD is enabled: '
        . 'the flag says what the account may use, the validation what the carrier takes.',
    carrier: Carrier::Gls,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: Scenario::cashOnDelivery(100.0))),
    refusedWith: ['notSupported'],
);
