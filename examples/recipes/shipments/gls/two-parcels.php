<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'GLS: two parcels',
    shows: 'Several parcels in one shipment: GLS takes up to 15, but limits the total value of the shipment (see '
        . 'gls/fifteen-parcels-refused).',
    carrier: Carrier::Gls,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(parcels: [Scenario::parcel(), Scenario::parcel()]),
);
