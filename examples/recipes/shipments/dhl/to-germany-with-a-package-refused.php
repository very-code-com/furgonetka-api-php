<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DHL: to Germany, as this parcel type',
    shows: 'For this route the API answers notInArray on /parcels/0/type for a parcel of type Package, which DHL '
        . 'takes at home. The parcel types a carrier accepts depend on the route.',
    carrier: Carrier::Dhl,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Hans Mueller', street: 'Unter den Linden 1', postcode: '10117', city: 'Berlin', countryCode: 'DE', email: 'hans@example.com', phone: '+491701234567')),
    refusedWith: ['notInArray'],
);
