<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: cash on delivery without the account owner is refused',
    shows: 'DPD answers isEmpty on /additional_services/cod/name when the account owner is missing, although the '
        . 'IBAN is optional in the specification.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: new Cod(100.0))),
    refusedWith: ['isEmpty'],
);
