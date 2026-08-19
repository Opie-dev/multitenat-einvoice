<?php

namespace App\Lhdn;

use RuntimeException;

class LhdnException extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $message, public readonly LhdnErrorKind $kind, public readonly ?int $httpStatus = null, public readonly array $payload = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $payload */
    public static function transient(string $message, ?int $status = null, array $payload = []): self
    {
        return new self($message, LhdnErrorKind::Transient, $status, $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function auth(string $message, ?int $status = null, array $payload = []): self
    {
        return new self($message, LhdnErrorKind::Auth, $status, $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function terminal(string $message, ?int $status = null, array $payload = []): self
    {
        return new self($message, LhdnErrorKind::Terminal, $status, $payload);
    }

    public static function breaker(string $message): self
    {
        return new self($message, LhdnErrorKind::Breaker);
    }
}
