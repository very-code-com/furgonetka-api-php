<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: fifteen parcels, the most a shipment can hold',
    shows: 'DPD takes up to 15 parcels in a shipment. The local rules refuse a 16th before any request is sent (a 16 '
        . 'parcel shipment is a validation error, see createPackage()).',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: array_fill(0, 15, Scenario::parcel())),
);
