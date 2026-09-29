<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown when a Furgonetka response cannot be interpreted: malformed JSON, a JSON value that is
 * not an object, an empty body where a document was expected, or a 2xx answer that is not the
 * file the endpoint promises.
 */
class FurgonetkaApiResponseParseException extends FurgonetkaApiException
{
}
