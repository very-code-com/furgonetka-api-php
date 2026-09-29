<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: to the United Kingdom is not served by this contract',
    shows: 'A contract does not serve every country: the API answers notAvailable on /receiver/country_code (and '
        . 'asks for customs data, /duty, which is a separate matter). UPS serves the UK on the same account.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', street: 'Downing Street 10', postcode: 'SW1A 1AA', city: 'London', countryCode: 'GB', email: 'anna@example.com', phone: '+447911123456')),
    refusedWith: ['notAvailable'],
);
