<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'FedEx: a domestic shipment',
    shows: 'FedEx is accepted for a plain domestic shipment: the same request as for DPD, on the contract with '
        . 'FedEx.',
    carrier: Carrier::Fedex,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
);
