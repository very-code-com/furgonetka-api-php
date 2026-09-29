<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: building and flat number in the street',
    shows: 'For Poland the building and the flat number go into the street line ("Florianska 15/7"). Separate '
        . 'building and flat fields exist only for Ukraine.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', street: 'Florianska 15/7', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'anna@example.com', phone: '600700800')),
);
