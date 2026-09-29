<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown when local pre-flight validation fails, before any network call is made.
 *
 * Local validation enforces only rules that Furgonetka's documentation states outright:
 * mandatory fields, name and description length limits per carrier, parcel counts, mutually
 * exclusive services, duty data for the destinations that need it. Everything else is left to
 * the API; `validateRemotely()` is the authority when the two disagree.
 */
class FurgonetkaApiValidationException extends FurgonetkaApiException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Furgonetka shipment validation failed: ' . implode('; ', $errors));
    }
}
