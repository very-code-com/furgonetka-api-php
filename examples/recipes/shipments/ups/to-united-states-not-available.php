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
    title: 'UPS: the United States are not served by this contract',
    shows: 'The account\'s UPS contract does not serve the US: notAvailable on /receiver/country_code, whatever else '
        . 'is right. The US and Canada also need a state or province in Receiver::county.',
    carrier: Carrier::Ups,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(receiver: new Receiver(name: 'John Smith', street: '1600 Pennsylvania Avenue NW', postcode: '20500', city: 'Washington', countryCode: 'US', county: 'DC', email: 'john@example.com', phone: '+12025550123'), duty: new Duty([Scenario::customsItem()], DutyType::Gift)),
    refusedWith: ['notAvailable'],
);
