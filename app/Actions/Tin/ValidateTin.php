<?php

namespace App\Actions\Tin;

use App\Data\Requests\Tin\ValidateTinData;
use App\Data\Resources\TinValidationData;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Lhdn\LhdnClientFactory;
use App\Models\Issuer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

class ValidateTin
{
    public function __construct(private readonly LhdnClientFactory $clients, private readonly TenantContext $context) {}

    public function handle(ValidateTinData $data): TinValidationData
    {
        $env = $this->context->environment();
        $key = 'tin:'.$this->context->tenant()->getKey().':'.$env->value.':'.sha1($data->tin.'|'.$data->id_type->value.'|'.$data->id_number);
        /** @var array{valid: bool, checked_at: string}|null $cached */
        $cached = Cache::get($key);
        if ($cached !== null) {
            return new TinValidationData($data->tin, $data->id_type->value, $data->id_number, $cached['valid'], $cached['checked_at'], true);
        }
        $issuer = $this->resolveIssuer($data->issuer_id);
        $valid = $this->clients->for($issuer)->validateTin($env, $data->tin, $data->id_type->value, $data->id_number, $issuer);
        $checkedAt = now()->toIso8601String();
        Cache::put($key, ['valid' => $valid, 'checked_at' => $checkedAt], now()->addHours((int) config('lhdn.tin_cache_hours', 24)));

        return new TinValidationData($data->tin, $data->id_type->value, $data->id_number, $valid, $checkedAt, false);
    }

    private function resolveIssuer(?string $issuerId): Issuer
    {
        if ($issuerId !== null) {
            return Issuer::forCurrentEnvironment()->find($issuerId) ?? throw new ProblemException(404, 'Not Found', 'Issuer not found.', 'issuer_not_found');
        }

        return Issuer::forCurrentEnvironment()->where('status', IssuerStatus::Active)->orderBy('created_at')->first()
            ?? Issuer::forCurrentEnvironment()->orderBy('created_at')->first()
            ?? throw ProblemException::conflict('Create an issuer in this environment before validating TINs.', 'issuer_required');
    }
}
