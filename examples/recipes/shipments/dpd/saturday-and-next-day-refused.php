<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: Saturday delivery with a next-day guarantee is refused',
    shows: 'The two services exclude each other: the API answers noAll on both fields. Choose one; Saturday delivery '
        . 'is accepted on its own (dpd/saturday-delivery).',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(saturdayDelivery: true, guaranteeNextDay: true)),
    refusedWith: ['noAll'],
);
