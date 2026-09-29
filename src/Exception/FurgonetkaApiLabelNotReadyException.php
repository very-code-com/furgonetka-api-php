<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown when Furgonetka answers 204 to a request for a label or a protocol: the shipment
 * exists but the carrier has not delivered the document yet.
 *
 * This is expected shortly after ordering, so it is a type of its own rather than a generic
 * error. Wait a moment and ask again.
 */
class FurgonetkaApiLabelNotReadyException extends FurgonetkaApiException
{
}
