<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentLine> */
class DocumentLineFactory extends Factory
{
    protected $model = DocumentLine::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'position' => 1,
            'classification_code' => '022',
            'description' => 'Item',
            'quantity' => '1.0000',
            'unit_code' => 'C62',
            'unit_price' => '10.0000',
            'discount_amount' => '0.00',
            'tax_type' => '06',
            'tax_amount' => '0.00',
            'subtotal' => '10.00',
            'total' => '10.00',
        ];
    }
}
