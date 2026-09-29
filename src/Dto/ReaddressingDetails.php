<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\ReaddressingStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The details of a readdressing (`details` of `GET /packages/readdressing/{uuid}`): the new
 * receiver, what it costs, where it stands and which shipments it covers.
 *
 * The shipments come as `packages_data`, a group of groups that repeats an order summary. They are
 * left in {@see self::$packagesData}; {@see self::packageIds()} lists their ids. Read from the
 * specification, not from a captured body: no readdressing was accepted on the sandbox.
 */
final class ReaddressingDetails
{
    /**
     * @param PackageAddress|null  $newReceiver        The receiver the shipment goes to now.
     * @param float|null           $price              What the readdressing costs.
     * @param ReaddressingStatus|null $status          Payment state; null when it is not a member.
     * @param string|null          $statusValue        State as sent.
     * @param string|null          $info               Free text from Furgonetka.
     * @param array<string, mixed> $packagesData       The order summary of the shipments, as sent.
     */
    public function __construct(
        public readonly ?PackageAddress $newReceiver = null,
        public readonly ?float $price = null,
        public readonly ?ReaddressingStatus $status = null,
        public readonly ?string $statusValue = null,
        public readonly ?string $info = null,
        public readonly array $packagesData = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $receiver = Value::object($data, 'newReceiver');

        return new self(
            $receiver !== null ? PackageAddress::fromArray($receiver) : null,
            Value::float($data, 'price'),
            Value::enum($data, 'status', ReaddressingStatus::class),
            Value::string($data, 'status'),
            Value::string($data, 'info'),
            Value::object($data, 'packages_data') ?? [],
        );
    }

    /**
     * The ids of the shipments in the order summary, each once, in the order they came.
     *
     * @return list<string>
     */
    public function packageIds(): array
    {
        $ids = [];

        foreach (Value::objectList($this->packagesData, 'packages_groups') as $group) {
            foreach (Value::objectList($group, 'packages') as $package) {
                $id = Value::string($package, 'id');

                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }
}
