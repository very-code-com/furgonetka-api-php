<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: cash on delivery',
    shows: 'Cod carries the amount and the account the carrier pays it out to: the IBAN and the name of the account '
        . 'owner (DPD needs the name). The COD amount may not exceed the value of the parcels unless the shipment '
        . 'is insured.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: new Cod(100.0, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski'))),
);
