<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: to Germany',
    shows: 'A shipment inside the EU needs no customs documents, only the country code and a phone number with the '
        . 'country prefix.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Hans Mueller', street: 'Unter den Linden 1', postcode: '10117', city: 'Berlin', countryCode: 'DE', email: 'hans@example.com', phone: '+491701234567')),
);
