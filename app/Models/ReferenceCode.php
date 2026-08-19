<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $set
 * @property string $code
 * @property string $description
 * @property array<string, mixed>|null $extra
 * @property string $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReferenceCode extends Model
{
    /** @var list<string> */
    public const SETS = [
        'document_types', 'tax_types', 'state_codes', 'payment_modes', 'classification_codes',
        'unit_types', 'currencies', 'country_codes', 'msic_codes',
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['extra' => 'array'];
    }
}
