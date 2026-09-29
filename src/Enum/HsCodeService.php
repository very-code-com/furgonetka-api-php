<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * The carrier whose HS codes (customs tariff codes) are searched (`service` of
 * `PUT /packages/duty/hs-codes-autocomplete-command/{uuid}`). The API takes these two.
 *
 * On the sandbox account Meest answered "no permission to this API resource" and NovaPost answered
 * with codes, so the search can be refused for a carrier the account cannot use.
 */
enum HsCodeService: string
{
    /** Meest. */
    case Meest = 'meest';

    /** Nova Post. */
    case NovaPost = 'novapost';
}
