<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: to Ireland, with a county',
    shows: 'Ireland needs a county (Receiver::county) as well as a postcode; the same goes for the US and Canada. '
        . 'The local rules ask for it before a request is sent.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Aoife Byrne', street: '1 Main Street', postcode: 'D02 X285', city: 'Dublin', countryCode: 'IE', county: 'Dublin', email: 'aoife@example.com', phone: '+353871234567')),
);
