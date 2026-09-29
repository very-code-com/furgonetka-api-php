<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;

/**
 * A shipping label as Furgonetka serves it: the bytes of a PDF, or the text of a ZPL or EPL label.
 *
 * The bytes are untouched. Write them to a file, or send a ZPL/EPL label to the printer as it is;
 * never treat them as UTF-8 text unless {@see self::isText()} says they are.
 *
 * The file format is fixed when the shipments are ordered (see {@see LabelOptions}); the page size
 * of a PDF can be chosen on every download.
 */
final class Label
{
    /**
     * @param string               $bytes       The label, byte for byte.
     * @param string               $contentType Media type Furgonetka sent, without parameters (`application/pdf` or `text/plain`).
     * @param LabelFileFormat|null $format      `pdf`, or `zpl` when the text holds ZPL commands; null for other text (an EPL label has no
     *                                          marker that is safe to recognise, so it is not guessed).
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType,
        public readonly ?LabelFileFormat $format,
    ) {
    }

    /** True for a PDF label. */
    public function isPdf(): bool
    {
        return $this->format === LabelFileFormat::Pdf;
    }

    /** True for a label that is text (ZPL or EPL) rather than a PDF. */
    public function isText(): bool
    {
        return !$this->isPdf();
    }

    /** Size in bytes. */
    public function size(): int
    {
        return strlen($this->bytes);
    }
}
