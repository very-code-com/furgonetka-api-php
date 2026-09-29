<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 410: the resource existed but can no longer be served.
 *
 * Documented for `GET /packages/{id}/label`, where the label could not be produced. Compare
 * {@see FurgonetkaApiLabelNotReadyException}, which is the transient 204 "not there yet".
 */
class FurgonetkaApiGoneException extends FurgonetkaApiHttpException
{
}
