<?php

use App\Models\ServiceToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

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
