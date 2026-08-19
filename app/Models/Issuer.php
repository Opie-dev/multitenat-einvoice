<?php

namespace App\Models;

use App\Enums\Environment;
use App\Enums\IdType;
use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\IssuerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $tin
 * @property IdType $id_type
 * @property string $id_number
 * @property string|null $sst_number
 * @property string|null $tourism_tax_number
 * @property string $msic_code
 * @property string $business_activity_description
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string|null $address_line3
 * @property string $postcode
 * @property string $city
 * @property string $state_code
 * @property string $country_code
 * @property string $email
 * @property string $phone
 * @property Environment $environment
 * @property LhdnMode $lhdn_mode
 * @property bool $einvoice_required
 * @property bool $consolidation_enabled
 * @property IssuerStatus $status
 * @property Carbon|null $tin_verified_at
 * @property Carbon|null $authorized_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $certificate_valid_until
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read IssuerSecret|null $secret
 */
class Issuer extends Model
{
    /** @use HasFactory<IssuerFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'environment' => Environment::class,
            'lhdn_mode' => LhdnMode::class,
            'status' => IssuerStatus::class,
            'einvoice_required' => 'boolean',
            'consolidation_enabled' => 'boolean',
            'tin_verified_at' => 'datetime',
            'authorized_at' => 'datetime',
            'activated_at' => 'datetime',
            'certificate_valid_until' => 'datetime',
        ];
    }

    /** @return HasOne<IssuerSecret, $this> */
    public function secret(): HasOne
    {
        return $this->hasOne(IssuerSecret::class);
    }

    /** @param  Builder<Issuer>  $query */
    public function scopeForCurrentEnvironment(Builder $query): void
    {
        $query->where('environment', app(TenantContext::class)->environment());
    }

    public function hasValidCertificate(): bool
    {
        return $this->certificate_valid_until !== null && $this->certificate_valid_until->isFuture();
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::forCurrentEnvironment()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
