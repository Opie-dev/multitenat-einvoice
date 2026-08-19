<?php

namespace App\Lhdn;

use App\Enums\Environment;
use App\Lhdn\Fake\FakeLhdnClient;
use App\Lhdn\Http\HttpLhdnClient;
use App\Models\Issuer;

class LhdnClientFactory
{
    public function __construct(private readonly CredentialsResolver $credentials) {}

    public function for(Issuer $issuer): LhdnClient
    {
        if ($this->isFake()) {
            return app(FakeLhdnClient::class);
        }

        return HttpLhdnClient::make($issuer->environment, $this->credentials->for($issuer));
    }

    public function forEnvironment(Environment $environment): LhdnClient
    {
        if ($this->isFake()) {
            return app(FakeLhdnClient::class);
        }

        return HttpLhdnClient::make($environment, $this->credentials->forIntermediary($environment));
    }

    private function isFake(): bool
    {
        return config('lhdn.driver') === 'fake';
    }
}
