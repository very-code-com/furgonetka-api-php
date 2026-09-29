<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Poczta Polska: Kurier48 needs a point on the sender',
    shows: 'Kurier48 is answered with onlyPocztexAvailable, and the sender has to be a point (isEmpty on '
        . '/pickup/point). Only one of the eight services can be chosen in any case.',
    carrier: Carrier::Poczta,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(pocztaKurier48: true)),
    refusedWith: ['onlyPocztexAvailable'],
);
