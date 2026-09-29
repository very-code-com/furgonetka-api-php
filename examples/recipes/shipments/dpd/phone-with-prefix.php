<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a phone number with the country prefix',
    shows: 'A Polish number is accepted as 600700800 and as +48600700800. A foreign number needs the leading plus '
        . 'and the country prefix, or the API answers prefixWithPlusRequired.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'anna@example.com', phone: '+48600700800')),
);
