<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;

return Recipe::shipment(
    title: 'Poczta Polska: other data on the label is refused',
    shows: 'The API answers on /sender: Poczta is on the documented list only with Ekspres24, which this contract '
        . 'does not have.',
    carrier: Carrier::Poczta,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(labelSender: new LabelSender('Prosta 1', '00-838', 'Warszawa', name: 'Shop Team', company: 'Example Shop', countryCode: 'PL', email: 'shop@example.com', phone: '500100200')),
    refusedWith: ['name'],
);
