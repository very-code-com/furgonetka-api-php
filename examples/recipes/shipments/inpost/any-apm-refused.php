<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: "any locker" is not a point on this account',
    shows: 'The special point values any_apm and any_parcel_shop, which let the carrier choose, are answered with '
        . 'invalid on /receiver/point here. Look a point up and use its code.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', countryCode: 'PL', email: 'anna@example.com', phone: '600700800', point: 'any_apm')),
    refusedWith: ['invalid'],
);
