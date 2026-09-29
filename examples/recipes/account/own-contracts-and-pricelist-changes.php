<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: your own contracts and announced price list changes',
    shows: 'listServiceAgreements() lists the contracts of your own with carriers (empty for an account that uses '
        . 'Furgonetka\'s), and listPlannedPricelistChanges() the price changes Furgonetka has announced.',
    query: static function (FurgonetkaApiClient $client): array {
        $agreements = $client->listServiceAgreements();
        $changes = $client->listPlannedPricelistChanges();
        $lines = [count($agreements) . ' own contracts, ' . count($changes) . ' announced price list changes'];
        foreach ($agreements as $agreement) {
            $lines[] = sprintf('service %s: %s (%s)', (string) $agreement->serviceId, (string) $agreement->name, (string) $agreement->service);
        }
        foreach ($changes as $change) {
            $lines[] = sprintf('%s %s %s', $change->date?->format('Y-m-d') ?? '?', (string) $change->service, (string) $change->url);
        }

        return $lines;
    },
);
