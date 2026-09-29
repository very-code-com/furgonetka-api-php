<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'ORLEN Paczka: from a point to a point',
    shows: 'ORLEN Paczka works between points only: the sender leaves the parcel at one and the receiver collects it '
        . 'at another, so both Pickup::point and Receiver::point are needed. The points of ORLEN come without a '
        . 'postcode, and the sender\'s address needs one, so it is given here.',
    carrier: Carrier::Orlen,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(pickup: $s->pointPickup(PointService::Orlen, 'Warszawa, Widok 22', postcode: '00-023'), receiver: $s->pointReceiver(PointService::Orlen, 'Warszawa, Zurawia 47')),
);
