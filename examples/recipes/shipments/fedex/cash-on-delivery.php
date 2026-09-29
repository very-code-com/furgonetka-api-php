<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'FedEx: cash on delivery',
    shows: 'Cod is accepted by FedEx for a domestic shipment, with the account the amount is paid out to in '
        . 'Cod::iban and Cod::name.',
    carrier: Carrier::Fedex,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(cod: Scenario::cashOnDelivery(100.0))),
);
