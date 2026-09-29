<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DHL: a pallet',
    shows: 'A pallet is a parcel of type Pallet with the pallet\'s size and weight. DHL prices it far above a parcel '
        . 'and takes up to five in a shipment.',
    carrier: Carrier::Dhl,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [new Parcel(ParcelType::Pallet, 120, 80, 100, 200.0, 2000.0, 'Machine parts')]),
);
