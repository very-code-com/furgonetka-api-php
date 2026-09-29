<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a return document to a private person',
    shows: 'ROD and private delivery (privateShipping) are compatible, and both are flags on AdditionalServices.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(rod: true, privateShipping: true)),
);
