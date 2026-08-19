<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\DocumentBuyerData;
use App\Exceptions\ProblemException;
use App\Models\Buyer;
use Illuminate\Validation\ValidationException;

/**
 * Turns the request's buyer block into the immutable snapshot stored on the document.
 * Documents never join to the registry for their party details: what LHDN received
 * must stay exactly what the caller sent, even after the buyer record changes.
 */
class ResolveBuyerSnapshot
{
    public const GENERAL_PUBLIC_TIN = 'EI00000000010';

    /** @return array{buyer_id: ?string, snapshot: array<string, mixed>} */
    public function resolve(DocumentBuyerData $buyer): array
    {
        return match ($buyer->mode()) {
            'buyer_id' => $this->fromRegistry((string) $buyer->buyer_id),
            'general_public' => ['buyer_id' => null, 'snapshot' => [
                'general_public' => true,
                'name' => 'General Public',
                'tin' => self::GENERAL_PUBLIC_TIN,
                'id_type' => 'BRN',
                'id_number' => 'NA',
                'country_code' => 'MYS',
            ]],
            'inline' => ['buyer_id' => null, 'snapshot' => $this->compact([
                'general_public' => false,
                'name' => $buyer->name,
                'tin' => $buyer->tin,
                'id_type' => $buyer->id_type?->value,
                'id_number' => $buyer->id_number,
                'sst_number' => $buyer->sst_number,
                'email' => $buyer->email,
                'phone' => $buyer->phone,
                'address_line1' => $buyer->address_line1,
                'address_line2' => $buyer->address_line2,
                'address_line3' => $buyer->address_line3,
                'postcode' => $buyer->postcode,
                'city' => $buyer->city,
                'state_code' => $buyer->state_code,
                'country_code' => $buyer->country_code ?? 'MYS',
            ])],
            default => throw ValidationException::withMessages([
                'buyer' => 'Provide exactly one of buyer_id, general_public=true, or inline buyer fields (name, …).',
            ]),
        };
    }

    /** @return array{buyer_id: ?string, snapshot: array<string, mixed>} */
    private function fromRegistry(string $id): array
    {
        $record = Buyer::query()->find($id)
            ?? throw new ProblemException(404, 'Not Found', 'Buyer not found.', 'buyer_not_found');

        return ['buyer_id' => $record->id, 'snapshot' => $this->compact([
            'general_public' => $record->general_public,
            'name' => $record->name,
            'tin' => $record->tin,
            'id_type' => $record->id_type?->value,
            'id_number' => $record->id_number,
            'sst_number' => $record->sst_number,
            'email' => $record->email,
            'phone' => $record->phone,
            'address_line1' => $record->address_line1,
            'address_line2' => $record->address_line2,
            'address_line3' => $record->address_line3,
            'postcode' => $record->postcode,
            'city' => $record->city,
            'state_code' => $record->state_code,
            'country_code' => $record->country_code,
        ])];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function compact(array $snapshot): array
    {
        return array_filter($snapshot, static fn (mixed $value): bool => $value !== null);
    }
}
