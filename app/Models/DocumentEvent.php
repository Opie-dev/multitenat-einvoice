<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property DocumentStatus|null $from_status
 * @property DocumentStatus $to_status
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property Carbon $created_at
 * @property-read Document $document
 */
class DocumentEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => DocumentStatus::class,
            'to_status' => DocumentStatus::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
