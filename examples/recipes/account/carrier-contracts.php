<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the carrier contracts',
    shows: 'listServices() gives the contracts of the account: Furgonetka\'s own (owner furgonetka) and your own '
        . '(owner client). A shipment names a contract by its id (service_id), not a carrier. Not every contract on '
        . 'the list can be used for every shipment.',
    query: static function (FurgonetkaApiClient $client): array {
        $lines = [];
        foreach ($client->listServices() as $service) {
            $lines[] = sprintf('%-10s %-16s owner %-10s COD %-5s national only %s', (string) $service->id, (string) ($service->carrier->value ?? $service->carrierName), (string) $service->ownerValue, var_export($service->codEnabled, true), var_export($service->nationalOnly, true));
        }

        return $lines;
    },
);
