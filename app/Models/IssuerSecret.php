<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $issuer_id
 * @property string|null $lhdn_client_id
 * @property string|null $lhdn_client_secret
 * @property string|null $signing_certificate
 * @property string|null $signing_key
 * @property string|null $cert_subject
 * @property string|null $cert_serial
 * @property string|null $cert_fingerprint
 * @property Carbon|null $cert_not_before
 * @property Carbon|null $cert_not_after
 * @property int|null $expiry_notified_at_days
 * @property Carbon|null $credentials_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IssuerSecret extends Model
{
    use BelongsToTenant, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['lhdn_client_id', 'lhdn_client_secret', 'signing_certificate', 'signing_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lhdn_client_id' => 'encrypted',
            'lhdn_client_secret' => 'encrypted',
            'signing_certificate' => 'encrypted',
            'signing_key' => 'encrypted',
            'cert_not_before' => 'datetime',
            'cert_not_after' => 'datetime',
            'expiry_notified_at_days' => 'integer',
            'credentials_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issuer, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    public function hasCredentials(): bool
    {
        return filled($this->lhdn_client_id) && filled($this->lhdn_client_secret);
    }

    public function hasCertificate(): bool
    {
        return filled($this->signing_certificate) && filled($this->signing_key);
    }
}
