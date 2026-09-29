<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Meest: a domestic shipment is refused',
    shows: 'Some contracts serve one corridor only: Meest delivers to Ukraine, so a shipment inside Poland is '
        . 'answered with wrong on /receiver/country_code ("the country of delivery has to be Ukraine"), among other '
        . 'errors.',
    carrier: Carrier::Meest,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
    refusedWith: ['wrong'],
);
