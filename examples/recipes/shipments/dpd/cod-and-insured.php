<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: cash on delivery of a valuable parcel',
    shows: 'A parcel worth 500 PLN with a COD of 500 PLN: the declared value both insures the parcel and allows the '
        . 'COD to be that high.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(30, 40, 20, 5.0, 500.0, 'Camera')], services: new AdditionalServices(cod: new Cod(500.0, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski'))),
);
