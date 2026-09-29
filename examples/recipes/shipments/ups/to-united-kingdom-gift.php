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
    title: 'UPS: a gift to the United Kingdom',
    shows: 'The UK needs customs data. A gift (DutyType::Gift) is the simplest declaration: the items, their value '
        . 'and weight, and nothing about EORI numbers.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'Anna Nowak', street: 'Downing Street 10', postcode: 'SW1A 1AA', city: 'London', countryCode: 'GB', email: 'anna@example.com', phone: '+447911123456'), duty: new Duty([Scenario::customsItem()], DutyType::Gift)),
);
