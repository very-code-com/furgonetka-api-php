<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Xpress Delivery: an offer that has ended',
    shows: 'A contract can stay on the account after the carrier\'s offer has ended: the API answers '
        . 'xpressDeliveryUnavailable on /service ("available until 30.09.2024"). Do not build a choice of carriers '
        . 'from the contract list alone.',
    carrier: Carrier::XpressDelivery,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
    refusedWith: ['xpressDeliveryUnavailable'],
);
