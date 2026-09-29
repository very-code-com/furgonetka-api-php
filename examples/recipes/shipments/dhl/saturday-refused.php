<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DHL: Saturday delivery is not available',
    shows: 'saturdayDelivery is answered with notAvailable for this route.',
    carrier: Carrier::Dhl,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(saturdayDelivery: true)),
    refusedWith: ['notAvailable'],
);
