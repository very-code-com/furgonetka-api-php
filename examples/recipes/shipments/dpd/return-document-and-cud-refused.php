<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a return document together with CUD is refused',
    shows: 'Some services exclude each other: rod with cud is answered with disableForTwo on the first, and cud is '
        . 'not supported by DPD at all (notSupported).',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(rod: true, cud: true)),
    refusedWith: ['disableForTwo', 'notSupported'],
);
