<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $issuer_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property string|null $cert_fingerprint
 * @property Carbon $replaced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IssuerSecretHistory extends Model
{
    use BelongsToTenant, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'replaced_at' => 'datetime',
        ];
    }
}
