<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 404: the shipment, command or other resource does not exist.
 *
 * Sandbox and production are separate installations with their own identifiers, so an id taken
 * from one environment and looked up in the other is a likely way to end up here.
 */
class FurgonetkaApiNotFoundException extends FurgonetkaApiHttpException
{
}
