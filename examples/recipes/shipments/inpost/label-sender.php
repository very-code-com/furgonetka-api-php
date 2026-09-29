<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'InPost: other data on the label',
    shows: 'InPost is on the documented list of carriers that take label data; the label shows the shop, returns go '
        . 'there.',
    carrier: Carrier::InPost,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(labelSender: new LabelSender('Prosta 1', '00-838', 'Warszawa', name: 'Shop Team', company: 'Example Shop', countryCode: 'PL', email: 'shop@example.com', phone: '500100200')),
);
