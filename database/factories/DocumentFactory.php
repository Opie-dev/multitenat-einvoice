<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'issuer_id' => Issuer::factory(),
            'environment' => Environment::Sandbox,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'buyer_snapshot' => ['general_public' => true, 'name' => 'General Public', 'tin' => 'EI00000000010', 'id_type' => 'BRN', 'id_number' => 'NA'],
            'currency' => 'MYR',
            'issue_date' => now()->toDateString(),
            'subtotal' => '0.00',
            'discount_total' => '0.00',
            'total_excluding_tax' => '0.00',
            'tax_total' => '0.00',
            'total_including_tax' => '0.00',
            'total_payable' => '0.00',
            'consolidate' => false,
            'source_system' => 'test',
            'source_ref' => 'ref-'.Str::lower(Str::random(10)),
            'payload_hash' => hash('sha256', Str::random(16)),
        ];
    }

    public function queued(): static
    {
        return $this->state(['status' => DocumentStatus::Queued, 'validated_at' => now()]);
    }

    public function held(HeldReason $reason): static
    {
        return $this->state(['status' => DocumentStatus::Held, 'held_reason' => $reason, 'validated_at' => now()]);
    }

    public function valid(): static
    {
        return $this->state([
            'status' => DocumentStatus::Valid,
            'validated_at' => now(),
            'submitted_at' => now(),
            'lhdn_uuid' => Str::upper(Str::random(26)),
            'lhdn_status_at' => now(),
        ]);
    }
}
