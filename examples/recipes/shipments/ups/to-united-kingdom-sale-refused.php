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
    title: 'UPS: a sale to the United Kingdom needs a real EORI and documents',
    shows: 'A sale (DutyType::Sale) needs the sender\'s EORI number, which the API checks for its format '
        . '(invalidEori), and the customs documents (Duty::documentsUuids, uploaded with uploadFile()). A made-up '
        . 'number is refused.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', street: 'Downing Street 10', postcode: 'SW1A 1AA', city: 'London', countryCode: 'GB', email: 'anna@example.com', phone: '+447911123456'), duty: new Duty([Scenario::customsItem()], DutyType::Sale, eori: 'PL1234567890123', vatNumber: 'GB123456789', transportPrice: 25.0)),
    refusedWith: ['invalidEori'],
);
