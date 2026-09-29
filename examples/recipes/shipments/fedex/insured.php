<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'FedEx: a valuable parcel',
    shows: 'The declared value of the parcel is 3000 PLN here, and FedEx accepts it.',
    carrier: Carrier::Fedex,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Parcel::package(30, 40, 20, 5.0, 3000.0, 'Phone')]),
);
