<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a misspelt service is silently ignored',
    shows: 'A key of AdditionalServices::extra that the API does not know is not an error: the shipment is accepted '
        . 'and priced without it. Nothing warns you, so a typo means a service you asked for and did not get. '
        . 'Prefer the typed fields, and compare the price details after a change.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => new ShipmentRequest(
        serviceId: $s->serviceId(),
        pickup: Scenario::pickup(),
        receiver: Scenario::receiver(),
        parcels: [Scenario::parcel()],
        additionalServices: new AdditionalServices(extra: ['no_such_service' => true]),
    ),
);
