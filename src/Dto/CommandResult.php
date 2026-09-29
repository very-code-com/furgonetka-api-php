<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * What every command answers when its status is read (`GET /<x>-command/{uuid}`): the UUID, the
 * status, when it last changed and the errors, plus whatever the particular command adds.
 *
 * **A failed command is not an exception.** Furgonetka answers 200 and puts the outcome in the
 * body, because a command can succeed for some shipments and fail for others (`partial_success`),
 * and a carrier's rejection arrives here, not as an HTTP error. So a finished result has to be
 * read: {@see self::isSuccessful()}, {@see self::isPartial()}, {@see self::$errors}, and
 * {@see self::errorsFor()} to find the error of one shipment.
 *
 * Errors name their shipment in `path`, as `/packages/id/{id}`; an error without such a path is
 * about the command as a whole.
 */
abstract class CommandResult
{
    /** The path prefix of an error about one shipment. */
    private const PACKAGE_PATH = '#^/packages/id/(\d+)$#D';

    /**
     * @param string|null             $uuid           The UUID the command was submitted under.
     * @param CommandStatus|null      $status         Status; null when it is not a member (a status added after this release).
     * @param string|null             $statusValue    Status as sent; a value that is not a member is still visible here.
     * @param \DateTimeImmutable|null $datetimeChange When the command last changed.
     * @param list<ApiError>          $errors         Every error, of the whole command or of one shipment.
     * @param array<string, mixed>    $raw            The whole answer.
     */
    public function __construct(
        public readonly ?string $uuid = null,
        public readonly ?CommandStatus $status = null,
        public readonly ?string $statusValue = null,
        public readonly ?\DateTimeImmutable $datetimeChange = null,
        public readonly array $errors = [],
        protected readonly array $raw = [],
    ) {
    }

    /**
     * Read the parts every command has; subclasses add theirs on top.
     *
     * @param array<string, mixed> $data
     * @return array{?string, ?CommandStatus, ?string, ?\DateTimeImmutable, list<ApiError>}
     */
    protected static function common(array $data): array
    {
        return [
            Value::string($data, 'uuid'),
            Value::enum($data, 'status', CommandStatus::class),
            Value::string($data, 'status'),
            Value::dateTime($data, 'datetime_change'),
            array_map(ApiError::fromArray(...), Value::objectList($data, 'errors')),
        ];
    }

    /** True when Furgonetka is done with the command, one way or the other. */
    public function isFinished(): bool
    {
        return $this->status?->isTerminal() === true;
    }

    /** True when the command is done and everything in it succeeded. */
    public function isSuccessful(): bool
    {
        return $this->status === CommandStatus::Successful;
    }

    /** True when the command is done, some shipments succeeded and some did not. */
    public function isPartial(): bool
    {
        return $this->status === CommandStatus::PartialSuccess;
    }

    /** True when the command is done and nothing succeeded. */
    public function hasFailed(): bool
    {
        return $this->status === CommandStatus::Error;
    }

    /** True when the command was aborted before it started, so nothing was done and nothing failed. */
    public function isCancelled(): bool
    {
        return $this->status === CommandStatus::Cancelled;
    }

    /**
     * The errors about one shipment.
     *
     * @return list<ApiError>
     */
    public function errorsFor(string|int $packageId): array
    {
        return array_values(array_filter(
            $this->errors,
            static fn (ApiError $error): bool => self::packageIdOf($error) === (string) $packageId,
        ));
    }

    /**
     * The shipments that have an error, in the order the errors came.
     *
     * @return list<string>
     */
    public function failedPackageIds(): array
    {
        $ids = [];

        foreach ($this->errors as $error) {
            $id = self::packageIdOf($error);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The errors that are not about a particular shipment.
     *
     * @return list<ApiError>
     */
    public function generalErrors(): array
    {
        return array_values(array_filter($this->errors, static fn (ApiError $error): bool => self::packageIdOf($error) === null));
    }

    /**
     * The whole answer as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    private static function packageIdOf(ApiError $error): ?string
    {
        return $error->path !== null && preg_match(self::PACKAGE_PATH, $error->path, $match) === 1 ? $match[1] : null;
    }
}
