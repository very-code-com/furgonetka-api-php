<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'FedEx: the Priority service is not supported',
    shows: 'fedexPriority is answered with notSupported on this contract.',
    carrier: Carrier::Fedex,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(services: new AdditionalServices(fedexPriority: true)),
    refusedWith: ['notSupported'],
);
