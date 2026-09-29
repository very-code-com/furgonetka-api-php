<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 409.
 *
 * Furgonetka answers 409 to `PUT /order-commands/{uuid}` and `PUT /pickup-commands/{uuid}` when the
 * account has not accepted the current regulations of the carrier, in the flat error shape:
 * `{"code": "terms_and_conditions_not_valid", "message": "The carrier's regulations have
 * changed. ...", "details": ""}` (seen on the sandbox on 2026-09-29, with FedEx). Read what is
 * pending for the shipments with `pendingRegulations()` and accept it with an explicit
 * `acceptRegulations()`; the library never accepts terms on your behalf.
 *
 * 409 is not "UUID already used": a repeated `PUT` with a used UUID answers 400 with the code
 * `commandExists`, see {@see FurgonetkaApiBadRequestException}.
 */
class FurgonetkaApiConflictException extends FurgonetkaApiHttpException
{
}
