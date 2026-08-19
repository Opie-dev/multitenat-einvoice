<?php

namespace App\Data\Resources;

use App\Models\ReferenceCode;
use Spatie\LaravelData\Data;

class ReferenceCodeData extends Data
{
    /** @param array<string, mixed>|null $extra */
    public function __construct(
        public string $code,
        public string $description,
        public ?array $extra,
    ) {}

    public static function fromModel(ReferenceCode $code): self
    {
        return new self($code->code, $code->description, $code->extra);
    }
}
