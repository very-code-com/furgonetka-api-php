<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: cash on delivery with a delivery time guarantee',
    shows: 'Services combine unless the carrier says otherwise: COD together with delivery guaranteed by 12:00.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(guarantee1200: true, cod: new Cod(100.0, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski'))),
);
