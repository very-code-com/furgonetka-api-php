<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the companies of the user',
    shows: 'An account may act for several companies. withCompany($id) returns a client for another one, sharing '
        . 'tokens and transport.',
    query: static function (FurgonetkaApiClient $client): array {
        $lines = [];
        foreach ($client->listCompanies() as $company) {
            $lines[] = sprintf('%-8s %-24s %-10s %s', (string) $company->id, (string) $company->name, (string) $company->accountType, $company->actual === true ? 'current' : '');
        }

        return $lines === [] ? ['No companies.'] : $lines;
    },
);
