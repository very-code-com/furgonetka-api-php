<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a company without a person',
    shows: 'A name or a company is required, not both: a receiver with only a company name is accepted.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(company: 'Nowak Sp. z o.o.', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'biuro@example.com', phone: '600700800')),
);
