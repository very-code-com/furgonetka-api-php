<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'ORLEN Paczka: an address delivery is refused',
    shows: 'Without points at both ends the API answers isEmpty on /pickup/point and /receiver/point.',
    carrier: Carrier::Orlen,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
    refusedWith: ['isEmpty'],
);
