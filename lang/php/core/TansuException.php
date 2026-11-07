<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use RuntimeException;

/**
 * An error the protocol reports to the client: a machine-readable code, an HTTP status and a message for people.
 */
final class TansuException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403, 'You are not allowed to do this.');
    }

    public static function notFound(string $path): self
    {
        return new self('not_found', 404, 'It does not exist.', $path);
    }

    public static function invalidPath(): self
    {
        return new self('invalid_path', 422, 'The path is not valid.');
    }

    public static function invalidName(): self
    {
        return new self('invalid_name', 422, 'The name is not valid.');
    }

    public static function nameTaken(string $path): self
    {
        return new self('name_taken', 409, 'That name is already taken.', $path);
    }

    public static function inUse(string $path): self
    {
        return new self('in_use', 409, 'The file is in use.', $path);
    }

    public static function fileTooLarge(): self
    {
        return new self('file_too_large', 413, 'The file is too large.');
    }

    public static function typeNotAllowed(): self
    {
        return new self('type_not_allowed', 415, 'This type of file is not allowed.');
    }

    public static function quotaExceeded(): self
    {
        return new self('quota_exceeded', 422, 'There is not enough room left for this file.');
    }

    /**
     * @return array{error: array{code: string, message: string, path?: string}}
     */
    public function toArray(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->path !== null) {
            $error['path'] = $this->path;
        }

        return ['error' => $error];
    }
}
