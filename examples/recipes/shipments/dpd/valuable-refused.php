<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a valuable shipment flag is not supported',
    shows: 'valuableShipment is answered with notSupported at DPD. Declare the value of the parcels instead.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(30, 40, 20, 5.0, 5000.0, 'Jewellery')], services: new AdditionalServices(valuableShipment: true)),
    refusedWith: ['notSupported'],
);
