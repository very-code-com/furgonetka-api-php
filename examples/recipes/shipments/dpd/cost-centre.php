<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Payer;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: a cost centre',
    shows: 'Payer::costCenter tags the shipment with a cost centre of yours, for your own bookkeeping.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(payer: new Payer('MPK-42')),
);
