<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Nova Post: a domestic shipment is refused',
    shows: 'Nova Post delivers to Lithuania, Latvia, Estonia, the Czech Republic and Ukraine, so a shipment inside '
        . 'Poland is answered with wrong on /receiver/country_code, and its phone numbers need the plus prefix.',
    carrier: Carrier::NovaPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
    refusedWith: ['wrong'],
);
