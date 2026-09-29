<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'AmbroExpress: a domestic shipment',
    shows: 'AmbroExpress is accepted for a plain domestic shipment (and priced higher than the parcel carriers).',
    carrier: Carrier::AmbroExpress,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
);
