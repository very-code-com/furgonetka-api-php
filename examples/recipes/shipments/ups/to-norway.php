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
    title: 'UPS: to Norway, with customs data',
    shows: 'Outside the EU the parcel has to be declared: Duty lists what is inside (ExportItem: description in the '
        . 'local language and in English, material, purpose, count, price, weight, tariff code) and why it is sent '
        . '(DutyType). Some carriers (GLS does) check that the weight of the items equals the weight of the parcel; '
        . 'here it does. The recipe only validates and prices: ordering it is refused (noSignature) until the sender '
        . 'has signed the customs documents on Furgonetka\'s own page.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Ola Nordmann', street: 'Karl Johans gate 1', postcode: '0154', city: 'Oslo', countryCode: 'NO', email: 'ola@example.com', phone: '+4791234567'), duty: new Duty([Scenario::customsItem()], DutyType::Gift)),
);
