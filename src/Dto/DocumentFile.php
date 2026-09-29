<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

/**
 * A file Furgonetka prepared that is not a label: a pickup protocol (`fetchProtocol()`), or the
 * documents of a documents command (`downloadDocuments()`), which are a PDF, a ZPL or EPL text, or a
 * ZIP of several.
 *
 * The bytes are untouched; write them to a file.
 */
final class DocumentFile
{
    /**
     * @param string $bytes       The file, byte for byte.
     * @param string $contentType Media type Furgonetka sent, without parameters; empty when it sent none.
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType,
    ) {
    }

    /** Size in bytes. */
    public function size(): int
    {
        return strlen($this->bytes);
    }

    /** True when the bytes start like a PDF. */
    public function isPdf(): bool
    {
        return str_starts_with($this->bytes, '%PDF-');
    }

    /** True when the bytes start like a ZIP archive. */
    public function isZip(): bool
    {
        return str_starts_with($this->bytes, "PK\x03\x04");
    }
}
