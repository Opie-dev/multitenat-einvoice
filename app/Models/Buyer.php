<?php

namespace App\Models;

use App\Enums\IdType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $tin
 * @property IdType|null $id_type
 * @property string|null $id_number
 * @property string|null $sst_number
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $address_line3
 * @property string|null $postcode
 * @property string|null $city
 * @property string|null $state_code
 * @property string $country_code
 * @property bool $general_public
 * @property Carbon|null $tin_validated_at
 * @property array<string, mixed>|null $tin_validation_result
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Buyer extends Model
{
    /** @use HasFactory<BuyerFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'general_public' => 'boolean',
            'tin_validated_at' => 'datetime',
            'tin_validation_result' => 'array',
        ];
    }
}
