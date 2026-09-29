<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Base exception for all Furgonetka errors.
 *
 * Every exception can optionally carry the raw body Furgonetka returned. The client attaches it
 * only when debugging is enabled, so that an unexpected or unrecognised error can be inspected
 * verbatim, together with a full stack trace, via {@see self::getDebugReport()}.
 *
 * The raw body is attached after redaction: a response from the OAuth token endpoint has its
 * `access_token` and `refresh_token` masked before it reaches an exception, because a debug
 * report tends to end up in a log file or a bug tracker.
 */
class FurgonetkaApiException extends \RuntimeException
{
    /** Raw Furgonetka response body associated with this error, if captured. */
    protected ?string $rawResponse = null;

    /** The raw Furgonetka response body that triggered this error, or null. */
    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }

    /**
     * Attach the raw Furgonetka response body to this exception (fluent).
     *
     * @return $this
     */
    public function withRawResponse(?string $rawResponse): static
    {
        $this->rawResponse = $rawResponse;
        return $this;
    }

    /**
     * Build a verbose debug report: exception class + message, the raw Furgonetka response
     * (when captured) and the full stack trace. Intended for logs or developer-facing output
     * when an unexpected error needs investigation.
     */
    public function getDebugReport(): string
    {
        $report = sprintf('%s: %s', static::class, $this->getMessage());

        if ($this->rawResponse !== null && $this->rawResponse !== '') {
            $report .= "\n\n--- Raw Furgonetka response ---\n" . $this->rawResponse;
        }

        if ($this->getPrevious() !== null) {
            $report .= "\n\n--- Caused by ---\n"
                . get_class($this->getPrevious()) . ': ' . $this->getPrevious()->getMessage();
        }

        $report .= "\n\n--- Stack trace ---\n" . $this->getTraceAsString();

        return $report;
    }
}
