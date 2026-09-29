<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\BillProduct;
use VeryCodeCom\FurgonetkaApi\Enum\BillType;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One operation on the account: a shipment charged, a refund, a correction, a top-up.
 *
 * `amount` is signed, minus for money that went out. `datetime` is written without an offset and is
 * read as Warsaw time (W18). `transaction` is the id of the shipment for a shipment operation and the
 * id of the bill otherwise, so it is text like every id here.
 */
final class Bill
{
    /**
     * @param int|null                $id                 Id of the operation; the cursor for `last_id`.
     * @param int|null                $companyId          Company the operation belongs to.
     * @param BillProduct|null        $product            Product; null when it is not a member.
     * @param string|null             $productValue       Product as sent.
     * @param float|null              $balanceBefore      Balance before the operation, PLN.
     * @param float|null              $amount             Signed.
     * @param string|null             $transaction        Shipment id or bill id, see above.
     * @param BillType|null           $type               Kind of operation; null when it is not a member.
     * @param string|null             $typeValue          Kind as sent.
     * @param string|null             $description        What it was, as text.
     * @param \DateTimeImmutable|null $datetime           When it happened.
     * @param int|null                $billsHistoryId     Id of the balance change.
     * @param list<BillPackage>       $packages           The shipments it concerns.
     * @param string|null             $descriptionFormatted The description with the waybill numbers in it.
     * @param float|null              $balanceAfter       Balance after the operation, PLN.
     * @param string|null             $currency           Currency; the specification does not list it, the sandbox sends "PLN".
     * @param string|null             $explainUrl         A page that explains the charge.
     * @param string|null             $explainPhrase      The part of the description to link to it.
     * @param array<string, mixed>    $raw                The whole object as Furgonetka sent it.
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $companyId = null,
        public readonly ?BillProduct $product = null,
        public readonly ?string $productValue = null,
        public readonly ?float $balanceBefore = null,
        public readonly ?float $amount = null,
        public readonly ?string $transaction = null,
        public readonly ?BillType $type = null,
        public readonly ?string $typeValue = null,
        public readonly ?string $description = null,
        public readonly ?\DateTimeImmutable $datetime = null,
        public readonly ?int $billsHistoryId = null,
        public readonly array $packages = [],
        public readonly ?string $descriptionFormatted = null,
        public readonly ?float $balanceAfter = null,
        public readonly ?string $currency = null,
        public readonly ?string $explainUrl = null,
        public readonly ?string $explainPhrase = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $explain = Value::object($data, 'url_to_explain_charge') ?? [];

        return new self(
            id: Value::int($data, 'id'),
            companyId: Value::int($data, 'company_id'),
            product: Value::enum($data, 'product', BillProduct::class),
            productValue: Value::string($data, 'product'),
            balanceBefore: Value::float($data, 'balance_before'),
            amount: Value::float($data, 'amount'),
            transaction: Value::string($data, 'transaction'),
            type: Value::enum($data, 'type', BillType::class),
            typeValue: Value::string($data, 'type'),
            description: Value::string($data, 'description'),
            datetime: Value::dateTime($data, 'datetime'),
            billsHistoryId: Value::int($data, 'bills_history_id'),
            packages: array_map(BillPackage::fromArray(...), Value::objectList($data, 'packages')),
            descriptionFormatted: Value::string($data, 'description_formatted'),
            balanceAfter: Value::float($data, 'balance_after'),
            currency: Value::string($data, 'currency'),
            explainUrl: Value::string($explain, 'url'),
            explainPhrase: Value::string($explain, 'phrase'),
            raw: $data,
        );
    }

    /**
     * The whole object as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
