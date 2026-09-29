<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a company as the receiver, with a contact person',
    shows: 'A receiver can have a company name and a person at once. Give the e-mail and phone of the person who '
        . 'will take the parcel.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(company: 'Nowak Sp. z o.o.', name: 'Anna Nowak', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'biuro@example.com', phone: '600700800')),
);
