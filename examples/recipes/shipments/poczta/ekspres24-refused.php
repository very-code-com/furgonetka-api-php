<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Poczta Polska: Ekspres24 is not supported',
    shows: 'pocztaEkspres24 is answered with notSupported on this contract.',
    carrier: Carrier::Poczta,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(pocztaEkspres24: true)),
    refusedWith: ['notSupported'],
);
