<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Poczta Polska: to Germany is not served here',
    shows: 'The contract serves Poland only: notAvailable on /receiver/country_code.',
    carrier: Carrier::Poczta,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Hans Mueller', street: 'Unter den Linden 1', postcode: '10117', city: 'Berlin', countryCode: 'DE', email: 'hans@example.com', phone: '+491701234567')),
    refusedWith: ['notAvailable'],
);
