<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: to the United Kingdom without customs data is refused',
    shows: 'A shipment to a country that needs customs data and has none is answered with isEmpty on /duty. The '
        . 'local rules refuse it before the request.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', street: 'Downing Street 10', postcode: 'SW1A 1AA', city: 'London', countryCode: 'GB', email: 'anna@example.com', phone: '+447911123456')),
    refusedWith: ['isEmpty'],
);
