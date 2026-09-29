<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'DPD: the plainest shipment',
    shows: 'The shape the other recipes start from: sender and receiver in Poland, one parcel of 30 x 40 x 20 cm and '
        . '5 kg worth 100 PLN. Dimensions are in centimetres, weight in kilograms, value in PLN. Furgonetka accepts '
        . 'it (the validation answers with an empty body) and gives a price.',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(),
);
