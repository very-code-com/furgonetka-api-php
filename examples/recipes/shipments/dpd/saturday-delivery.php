<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: Saturday delivery',
    shows: 'saturdayDelivery is accepted. It only makes sense for a shipment collected on a Friday, and the pickup '
        . 'date is not part of the shipment, so the library cannot check that for you.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(saturdayDelivery: true)),
);
