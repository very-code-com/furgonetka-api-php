<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: parcel types the carrier does not take',
    shows: 'Furniture, machines, vehicles and the type Other are types of the API, but a carrier accepts only some: '
        . 'DPD answers notInArray on /parcels/0/type. Ask validateRemotely() before you build a form around a type.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [new Parcel(ParcelType::Other, 30, 40, 20, 5.0, 100.0, 'Mixed goods', 1)]),
    refusedWith: ['notInArray'],
);
