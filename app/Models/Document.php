<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $issuer_id
 * @property string|null $buyer_id
 * @property string|null $group_id
 * @property Environment $environment
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property HeldReason|null $held_reason
 * @property array<string, mixed> $buyer_snapshot
 * @property string $currency
 * @property string|null $exchange_rate
 * @property Carbon $issue_date
 * @property string $subtotal
 * @property string $discount_total
 * @property string $total_excluding_tax
 * @property string $tax_total
 * @property string $total_including_tax
 * @property string $total_payable
 * @property bool $consolidate
 * @property string $source_system
 * @property string $source_ref
 * @property string|null $original_document_id
 * @property string|null $original_lhdn_uuid
 * @property array<string, mixed>|null $payment
 * @property array<string, mixed>|null $metadata
 * @property string $payload_hash
 * @property string|null $lhdn_uuid
 * @property string|null $lhdn_long_id
 * @property string|null $lhdn_submission_uid
 * @property array<int, mixed>|null $lhdn_errors
 * @property Carbon|null $validated_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $lhdn_status_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $consolidated_into_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Issuer $issuer
 * @property-read Buyer|null $buyer
 * @property-read Document|null $originalDocument
 * @property-read Collection<int, DocumentLine> $lines
 * @property-read Collection<int, DocumentEvent> $events
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const CANCELLATION_WINDOW_HOURS = 72;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'held_reason' => HeldReason::class,
            'buyer_snapshot' => 'array',
            'payment' => 'array',
            'metadata' => 'array',
            'lhdn_errors' => 'array',
            'consolidate' => 'boolean',
            'issue_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total_excluding_tax' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_including_tax' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'lhdn_status_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issuer, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'original_document_id');
    }

    /** @return HasMany<DocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position');
    }

    /** @return HasMany<DocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @param  Builder<Document>  $query */
    public function scopeForCurrentEnvironment(Builder $query): void
    {
        $query->where('environment', app(TenantContext::class)->environment());
    }

    public function isCancellable(): bool
    {
        return $this->status === DocumentStatus::Valid
            && $this->lhdn_status_at !== null
            && $this->lhdn_status_at->copy()->addHours(self::CANCELLATION_WINDOW_HOURS)->isFuture();
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::forCurrentEnvironment()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
