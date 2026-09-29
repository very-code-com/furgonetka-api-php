<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: cash on delivery in euro',
    shows: 'Cod::currency is PLN by default. Another currency is accepted, and the "not above the value" rule then '
        . 'no longer applies as it does for PLN.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: new Cod(100.0, currency: 'EUR', iban: 'PL61109010140000071219812874', name: 'Jan Kowalski'))),
);
