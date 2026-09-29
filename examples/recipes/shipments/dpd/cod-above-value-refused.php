<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: cash on delivery above the value is refused',
    shows: 'Collecting more than the parcels are worth needs insurance: the API answers invalidValueWithoutInsurance '
        . 'on the amount. Raise the declared value of the parcels, not the COD.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: new Cod(1500.0, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski'))),
    refusedWith: ['invalidValueWithoutInsurance'],
);
