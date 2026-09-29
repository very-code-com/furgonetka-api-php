<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Poczta Polska: ten parcels',
    shows: 'The documentation says one parcel per shipment; the API takes up to ten, which is the limit the library '
        . 'enforces.',
    carrier: Carrier::Poczta,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: array_fill(0, 10, Scenario::parcel())),
);
