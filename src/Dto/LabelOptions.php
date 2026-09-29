<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * How the label should look (`label` of an order or documents command).
 *
 * Whatever is left null is taken from the account's printing settings. The file format is fixed
 * when the shipments are ordered and cannot be changed afterwards; the page format of a PDF can be
 * chosen on every download.
 *
 *   LabelOptions::pdf(LabelPageFormat::A6)   // a PDF on an A6 sheet
 *   LabelOptions::zpl()                      // ZPL for a thermal printer
 */
final class LabelOptions
{
    /**
     * @param LabelFileFormat|null $fileFormat     `pdf`, `zpl` or `epl`.
     * @param LabelPageFormat|null $pageFormat     Sheet size of a PDF.
     * @param bool|null            $addCuttingLine Print cutting lines. Only for a PDF on A4, and only read by the documents command.
     */
    public function __construct(
        public readonly ?LabelFileFormat $fileFormat = null,
        public readonly ?LabelPageFormat $pageFormat = null,
        public readonly ?bool $addCuttingLine = null,
    ) {
    }

    /** A PDF label, optionally on a given sheet size. */
    public static function pdf(?LabelPageFormat $pageFormat = null, ?bool $addCuttingLine = null): self
    {
        return new self(LabelFileFormat::Pdf, $pageFormat, $addCuttingLine);
    }

    /** A ZPL label for Zebra thermal printers. */
    public static function zpl(): self
    {
        return new self(LabelFileFormat::Zpl);
    }

    /** An EPL label for older thermal printers. */
    public static function epl(): self
    {
        return new self(LabelFileFormat::Epl);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'file_format' => $this->fileFormat?->value,
            'page_format' => $this->pageFormat?->value,
            'add_cutting_line' => $this->addCuttingLine,
        ]);
    }
}
