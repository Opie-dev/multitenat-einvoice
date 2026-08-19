<?php

namespace App\Models;

use App\Enums\Environment;
use App\Tenancy\BelongsToTenant;
use Database\Factories\SubmissionAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $issuer_id
 * @property string|null $document_id
 * @property string|null $submission_uid
 * @property string $operation
 * @property Environment $environment
 * @property int|null $http_status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error_kind
 * @property string|null $error_message
 * @property int $duration_ms
 * @property Carbon $created_at
 */
class SubmissionAttempt extends Model
{
    /** @use HasFactory<SubmissionAttemptFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'request' => 'array',
            'response' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issuer, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
