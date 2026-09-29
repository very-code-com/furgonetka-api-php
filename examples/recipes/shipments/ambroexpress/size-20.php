<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'AmbroExpress: the 20 size',
    shows: 'ambroSize20 is a flag on AdditionalServices, and AmbroExpress accepts it for a domestic shipment.',
    carrier: Carrier::AmbroExpress,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(ambroSize20: true)),
);
