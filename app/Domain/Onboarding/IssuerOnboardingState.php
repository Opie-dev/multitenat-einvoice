<?php

namespace App\Domain\Onboarding;

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\IssuerStatus;
use App\Models\Document;
use App\Models\Issuer;

/**
 * Read-only, resumable derivation of the six-step issuer onboarding wizard.
 * Nothing here is stored: every step's done/pending state is computed fresh
 * from the issuer row(s), their secrets, and existing documents, so the same
 * derivation can back both the wizard and the vendor progress board.
 *
 * Issuer rows are environment-locked (unique per tenant+tin+environment, see
 * the issuers migration), so "the issuer in production" and "the issuer in
 * sandbox" are two different rows sharing the same tin. `profile`/`tin`
 * report on whichever row corresponds to $env (resolved via the tin, falling
 * back to $issuer itself when its own environment already matches); `mode`
 * and `certificate` do the same, explicitly, per the spec. `sandbox_test`
 * always looks at the sandbox row and `go_live` always looks at the
 * production row, regardless of which $env the caller is viewing — go-live
 * readiness is a statement about the pair, not about one environment tab.
 */
final class IssuerOnboardingState
{
    /**
     * The profile fields the dashboard's "profile" step writes, taken from
     * App\Data\Requests\UpdateIssuerData's required (non-Optional,
     * non-nullable) properties — excluding lhdn_mode (the "mode" step) and
     * the boolean toggles (einvoice_required, consolidation_enabled), which
     * aren't part of the profile section. tin/id_type/id_number are set at
     * issuer creation and are not editable here, so they're not checked.
     *
     * @var list<string>
     */
    private const REQUIRED_PROFILE_FIELDS = [
        'name', 'msic_code', 'business_activity_description',
        'address_line1', 'postcode', 'city', 'state_code',
        'country_code', 'email', 'phone',
    ];

    /**
     * Per-instance memo of siblingFor(), keyed by Environment::value. An
     * instance is scoped to one issuer (and thus one tin/tenant), so caching
     * the sandbox/production rows here for its lifetime is safe — steps()
     * and goLive() both need them and must not issue duplicate queries.
     *
     * @var array<string, ?Issuer>
     */
    private array $siblingCache = [];

    private function __construct(
        private readonly Issuer $issuer,
        private readonly Environment $env,
    ) {}

    public static function for(Issuer $issuer, Environment $env): self
    {
        return new self($issuer, $env);
    }

    /** @return list<StepState> */
    public function steps(): array
    {
        $viewed = $this->siblingFor($this->env);
        $sandbox = $this->siblingFor(Environment::Sandbox);
        $production = $this->siblingFor(Environment::Production);

        $profile = $viewed !== null && $this->profileDone($viewed);
        $tin = $viewed !== null && $this->tinDone($viewed);
        $mode = $viewed !== null && $this->modeDone($viewed);
        $certificate = $viewed !== null && $this->certificateDone($viewed);
        $sandboxTestDone = $sandbox !== null && $this->sandboxTestDone($sandbox);
        [$goLive, $blockedReason] = $this->goLive($sandbox, $production, $sandboxTestDone);

        return [
            new StepState('profile', $profile),
            new StepState('tin', $tin),
            new StepState('mode', $mode),
            new StepState('certificate', $certificate),
            new StepState('sandbox_test', $sandboxTestDone),
            new StepState('go_live', $goLive, $blockedReason),
        ];
    }

    public function current(): string
    {
        foreach ($this->steps() as $step) {
            if (! $step->done) {
                return $step->key;
            }
        }

        return 'go_live';
    }

    private function profileDone(Issuer $issuer): bool
    {
        foreach (self::REQUIRED_PROFILE_FIELDS as $field) {
            if (! filled($issuer->getAttribute($field))) {
                return false;
            }
        }

        return true;
    }

    private function tinDone(Issuer $issuer): bool
    {
        return $issuer->tin_verified_at !== null;
    }

    /**
     * Either the intermediary consent round-trip has succeeded (authorized_at
     * is the only record of that consent this schema keeps — see
     * AuthorizeIssuer, which sets it for both modes once LHDN accepts the
     * token call) or, for own-credentials issuers still waiting on that
     * round-trip, the client id/secret are already on file.
     */
    private function modeDone(Issuer $issuer): bool
    {
        return $issuer->authorized_at !== null || ($issuer->secret?->hasCredentials() ?? false);
    }

    private function certificateDone(Issuer $issuer): bool
    {
        return $issuer->secret?->hasCertificate() ?? false;
    }

    private function sandboxTestDone(Issuer $sandboxIssuer): bool
    {
        return Document::query()
            ->where('issuer_id', $sandboxIssuer->id)
            ->where('environment', Environment::Sandbox)
            ->where('status', DocumentStatus::Valid)
            ->where('source_system', 'dashboard-test')
            ->exists();
    }

    /**
     * Takes the already-resolved sandbox/production siblings and the
     * already-computed sandbox_test result — steps() has computed all three
     * once, and go-live readiness must not re-derive them.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function goLive(?Issuer $sandbox, ?Issuer $production, bool $sandboxTestDone): array
    {
        if ($production !== null && $production->status === IssuerStatus::Active) {
            return [true, null];
        }

        $reason = match (true) {
            $sandbox === null || ! $this->profileDone($sandbox) => 'profile_incomplete',
            ! $this->tinDone($sandbox) => 'tin_not_verified',
            ! $this->modeDone($sandbox) => 'mode_incomplete',
            ! $this->certificateDone($sandbox) => 'certificate_missing',
            ! $sandboxTestDone => 'sandbox_test_pending',
            $production === null || ! $this->modeDone($production) => 'production_mode_incomplete',
            ! $this->certificateDone($production) => 'production_certificate_missing',
            default => null,
        };

        return [false, $reason];
    }

    /**
     * The issuer row for $env: $issuer itself when it already lives there,
     * otherwise its same-tin sibling — memoized per instance so steps() and
     * goLive() never issue the same lookup twice.
     */
    private function siblingFor(Environment $env): ?Issuer
    {
        $key = $env->value;
        if (array_key_exists($key, $this->siblingCache)) {
            return $this->siblingCache[$key];
        }

        $sibling = $this->issuer->environment === $env
            ? $this->issuer
            : Issuer::query()->where('tin', $this->issuer->tin)->where('environment', $env)->first();

        return $this->siblingCache[$key] = $sibling;
    }
}
