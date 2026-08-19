<?php

namespace App\Services\Certificates;

use RuntimeException;

class InvalidCertificate extends RuntimeException
{
    public static function because(string $code): self
    {
        return new self($code);
    }
}
