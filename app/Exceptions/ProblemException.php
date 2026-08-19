<?php

namespace App\Exceptions;

use RuntimeException;

class ProblemException extends RuntimeException
{
    /** @param array<int, array{pointer?: string, code?: string, message: string}> $errors */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $detail = '',
        public readonly ?string $problemCode = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($detail !== '' ? $detail : $title);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, 'Not Found', $detail, 'not_found');
    }

    public static function unauthenticated(string $detail = 'Authentication required.'): self
    {
        return new self(401, 'Unauthenticated', $detail, 'unauthenticated');
    }

    public static function forbidden(string $detail = 'Forbidden.'): self
    {
        return new self(403, 'Forbidden', $detail, 'forbidden');
    }

    public static function conflict(string $detail, string $code): self
    {
        return new self(409, 'Conflict', $detail, $code);
    }

    public static function badRequest(string $detail, string $code): self
    {
        return new self(400, 'Bad Request', $detail, $code);
    }
}
