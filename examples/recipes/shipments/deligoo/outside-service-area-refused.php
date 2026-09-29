<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Deligoo: an address outside the service area is refused',
    shows: 'Deligoo serves certain areas only: Warsaw to Krakow is answered with pickupCityIsNotInArea and '
        . 'receiverCityIsNotInArea. Ask before you offer such a carrier for an address.',
    carrier: Carrier::Deligoo,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
    refusedWith: ['pickupCityIsNotInArea'],
);
