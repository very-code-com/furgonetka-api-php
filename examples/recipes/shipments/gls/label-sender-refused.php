<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'GLS: other data on the label is refused',
    shows: 'GLS does not take label data. The API answers on /sender, and the documentation contradicts itself on '
        . 'this, so the answer of the API decides. The library warns for carriers outside the documented list.',
    carrier: Carrier::Gls,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(labelSender: new LabelSender('Prosta 1', '00-838', 'Warszawa', name: 'Shop Team', company: 'Example Shop', countryCode: 'PL', email: 'shop@example.com', phone: '500100200')),
    refusedWith: ['name'],
);
