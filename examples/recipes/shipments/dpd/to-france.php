<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: to France',
    shows: 'The same as Germany with a French address. The postcode format is checked per country by Furgonetka.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Marie Dupont', street: '1 Rue de Rivoli', postcode: '75001', city: 'Paris', countryCode: 'FR', email: 'marie@example.com', phone: '+33612345678')),
);
