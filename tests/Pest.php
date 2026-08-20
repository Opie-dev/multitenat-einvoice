<?php

use App\Enums\Environment;
use App\Lhdn\Fake\FakeLhdnClient;
use App\Models\ApiKey;
use App\Models\ServiceToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Windows PHP builds need an OpenSSL config for openssl_pkey_export(); Linux does not.
if (getenv('OPENSSL_CONF') === false) {
    foreach ([
        'C:/Program Files/Git/usr/ssl/openssl.cnf',
        'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf',
    ] as $candidate) {
        if (is_file($candidate)) {
            putenv("OPENSSL_CONF={$candidate}");
            $_ENV['OPENSSL_CONF'] = $_SERVER['OPENSSL_CONF'] = $candidate;
            break;
        }
    }
}

require_once __DIR__.'/Support/Golden.php';

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->beforeEach(function () {
    $this->withoutVite();
})->in('Feature/Web');
pest()->extend(TestCase::class)->in('Unit');
pest()->extend(TestCase::class)->in('Integration');

function fakeLhdn(): FakeLhdnClient
{
    return app(FakeLhdnClient::class);
}

function serviceToken(array $abilities = ['*']): string
{
    return ServiceToken::generate('test-'.bin2hex(random_bytes(3)), $abilities)['plaintext'];
}

/** @return array<string,string> */
function serviceHeaders(Tenant $tenant, string $env = 'production', array $abilities = ['*']): array
{
    return [
        'Authorization' => 'Bearer '.serviceToken($abilities),
        'X-Tenant-Id' => $tenant->id,
        'X-Environment' => $env,
    ];
}

/** @return array<string,string> */
function apiKeyHeaders(Tenant $tenant, string $env = 'sandbox', array $abilities = ApiKey::ABILITIES): array
{
    ['plaintext' => $plain] = ApiKey::generate($tenant, 'test-key', Environment::from($env), $abilities);

    return ['Authorization' => 'Bearer '.$plain];
}
