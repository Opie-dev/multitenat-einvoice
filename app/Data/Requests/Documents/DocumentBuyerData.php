<?php

namespace App\Data\Requests\Documents;

use App\Enums\IdType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentBuyerData extends Data
{
    public function __construct(
        #[Max(26)] public ?string $buyer_id = null,
        public bool $general_public = false,
        #[Max(300)] public ?string $name = null,
        #[Max(20)] public ?string $tin = null,
        public ?IdType $id_type = null,
        #[Max(30)] public ?string $id_number = null,
        #[Max(40)] public ?string $sst_number = null,
        #[Email, Max(320)] public ?string $email = null,
        #[Max(20)] public ?string $phone = null,
        #[Max(150)] public ?string $address_line1 = null,
        #[Max(150)] public ?string $address_line2 = null,
        #[Max(150)] public ?string $address_line3 = null,
        #[Max(10)] public ?string $postcode = null,
        #[Max(50)] public ?string $city = null,
        #[Size(2)] public ?string $state_code = null,
        #[Size(3)] public ?string $country_code = null,
    ) {}

    /**
     * id_type and id_number are co-required. This object is always nested (under
     * `buyer`), so string rules like `required_with:id_number` would resolve their
     * parameter against the *root* payload rather than this object's own scope and
     * never fire. Compute presence from $context->payload directly instead, via a
     * lazy Rule::requiredIf() closure.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        $hasIdType = fn () => filled(data_get($context->payload, 'id_type'));
        $hasIdNumber = fn () => filled(data_get($context->payload, 'id_number'));

        return [
            'id_type' => ['nullable', new Enum(IdType::class), Rule::requiredIf($hasIdNumber)],
            'id_number' => ['nullable', 'string', 'max:30', Rule::requiredIf($hasIdType)],
        ];
    }

    /** @return 'buyer_id'|'general_public'|'inline'|'invalid' */
    public function mode(): string
    {
        $modes = array_filter([
            'buyer_id' => $this->buyer_id !== null && $this->buyer_id !== '',
            'general_public' => $this->general_public,
            'inline' => $this->name !== null && $this->name !== '',
        ]);
        if (count($modes) !== 1) {
            return 'invalid';
        }

        return (string) array_key_first($modes);
    }
}
