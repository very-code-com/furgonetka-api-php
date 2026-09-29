<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: delivery by 9:30 is not available',
    shows: 'Whether a guarantee is offered depends on the route: for Warsaw to Krakow the API answers '
        . 'notAvailable9300 on guarantee_0930, while 12:00 and next day are accepted.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(guarantee0930: true)),
    refusedWith: ['notAvailable9300'],
);
