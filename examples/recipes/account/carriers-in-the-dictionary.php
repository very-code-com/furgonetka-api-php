<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the carriers of the dictionary',
    shows: 'fetchDictionary() lists every carrier Furgonetka knows, which is more than an account has contracts '
        . 'with. It needs no login.',
    query: static function (FurgonetkaApiClient $client): array {
        $carriers = $client->fetchDictionary()->carriers();
        $lines = [count($carriers) . ' carriers in the dictionary:'];
        foreach ($carriers as $key => $name) {
            $lines[] = sprintf('%-18s %s', $key, $name);
        }

        return $lines;
    },
);
