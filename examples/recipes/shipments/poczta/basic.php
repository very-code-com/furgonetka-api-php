<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Poczta Polska: a domestic shipment',
    shows: 'Poczta Polska is accepted for a plain domestic shipment: the same request as for DPD, on the contract '
        . 'with Poczta Polska.',
    carrier: Carrier::Poczta,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
);
