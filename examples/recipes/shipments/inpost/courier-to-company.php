<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: a courier delivery to a company',
    shows: 'A company name alone is enough for the receiver, without a person: the API asks for a name or a company, '
        . 'not for both.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(company: 'Nowak Sp. z o.o.', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'biuro@example.com', phone: '600700800')),
);
