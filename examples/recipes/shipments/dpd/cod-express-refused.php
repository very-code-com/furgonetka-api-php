<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: express cash on delivery is refused',
    shows: 'Cod::express (the faster pay-out) is not something DPD accepts on this account: the API answers invalid '
        . 'on /additional_services/cod/express.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: new Cod(100.0, express: true, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski'))),
    refusedWith: ['invalid'],
);
