<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentBatchData;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Domain\Documents\TotalsCalculator;
use App\Domain\Documents\TotalsMismatch;
use App\Exceptions\ProblemException;
use App\Models\Issuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * All-or-nothing create for a group of documents. Every item is checked before
 * anything is written so the caller gets one 422 listing all bad items rather
 * than discovering them one failed request at a time.
 */
class CreateDocumentBatch
{
    public function __construct(
        private readonly CreateDocument $create,
        private readonly DocumentSemanticValidator $semantics,
        private readonly ResolveBuyerSnapshot $buyers,
        private readonly TotalsCalculator $totals,
    ) {}

    /** @return array{group_id: string, documents: list<DocumentCreated>} */
    public function handle(CreateDocumentBatchData $batch): array
    {
        $items = array_values($batch->documents->items());

        $errors = [];
        foreach ($items as $i => $item) {
            foreach ($this->itemErrors($item) as $key => $message) {
                $errors["documents.{$i}.{$key}"] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $groupId = (string) Str::ulid();
        /** @var list<DocumentCreated> $created */
        $created = DB::transaction(function () use ($items, $groupId): array {
            $out = [];
            foreach ($items as $item) {
                $out[] = $this->create->handle($item, $groupId);
            }

            return $out;
        });

        return ['group_id' => $groupId, 'documents' => $created];
    }

    /** @return array<string, string> */
    private function itemErrors(CreateDocumentData $item): array
    {
        $issuer = Issuer::forCurrentEnvironment()->find($item->issuer_id);
        if ($issuer === null) {
            return ['issuer_id' => 'Issuer not found.'];
        }

        $errors = $this->semantics->errors($item, $issuer);

        try {
            $this->buyers->resolve($item->buyer);
        } catch (ValidationException $e) {
            $errors['buyer'] = $e->errors()['buyer'][0] ?? 'Invalid buyer.';
        } catch (ProblemException) {
            $errors['buyer.buyer_id'] = 'Buyer not found.';
        }

        try {
            $this->totals->calculate(array_values($item->lines->items()), $item->totals);
        } catch (TotalsMismatch $e) {
            $errors[str_replace('/', '.', ltrim($e->pointer, '/'))] = "Value {$e->given} does not match the computed {$e->expected}.";
        }

        return $errors;
    }
}
