<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\DocumentLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $position
 * @property string $classification_code
 * @property string $description
 * @property string $quantity
 * @property string $unit_code
 * @property string $unit_price
 * @property string $discount_amount
 * @property string|null $discount_rate
 * @property string $tax_type
 * @property string|null $tax_rate
 * @property string $tax_amount
 * @property string|null $tax_exemption_reason
 * @property string $subtotal
 * @property string $total
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Document $document
 */
class DocumentLine extends Model
{
    /** @use HasFactory<DocumentLineFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'discount_rate' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
