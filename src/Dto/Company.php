<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A company the user belongs to (`GET /account/companies`). Use `id` with `withCompany()` to act
 * for that company.
 */
final class Company
{
    /**
     * @param int|null    $id          The company's identifier (`company_id`).
     * @param string|null $name        Name.
     * @param string|null $accountType Account type.
     * @param string|null $aclRole     The user's role in the company.
     * @param bool|null   $actual      True when the user is logged in as this company right now.
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $name = null,
        public readonly ?string $accountType = null,
        public readonly ?string $aclRole = null,
        public readonly ?bool $actual = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::int($data, 'company_id'),
            Value::string($data, 'name'),
            Value::string($data, 'account_type'),
            Value::string($data, 'acl_role'),
            Value::bool($data, 'actual'),
        );
    }
}
