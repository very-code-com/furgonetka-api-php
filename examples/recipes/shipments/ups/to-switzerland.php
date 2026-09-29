<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'UPS: to Switzerland, with customs data',
    shows: 'The same as Norway: Switzerland is outside the EU, so the shipment needs a customs declaration, and an '
        . 'order is refused (noSignature) until the sender has signed the customs documents on Furgonetka\'s own page.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Hans Muster', street: 'Bahnhofstrasse 1', postcode: '8001', city: 'Zurich', countryCode: 'CH', email: 'hans@example.com', phone: '+41441234567'), duty: new Duty([Scenario::customsItem()], DutyType::Gift)),
);
