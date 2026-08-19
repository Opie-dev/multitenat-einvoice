<?php

use App\Models\Tenant;

it('creates a tenant with a service token that has tenants:manage', function () {
    $this->withHeader('Authorization', 'Bearer '.serviceToken(['tenants:manage']))
        ->postJson('/v1/tenants', ['name' => 'Acme Sdn Bhd', 'billplz_account_id' => 'acct_1'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Acme Sdn Bhd')
        ->assertJsonPath('data.billplz_account_id', 'acct_1');
    expect(Tenant::where('billplz_account_id', 'acct_1')->exists())->toBeTrue();
});

it('validates tenant creation', function () {
    $this->withHeader('Authorization', 'Bearer '.serviceToken())
        ->postJson('/v1/tenants', [])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/name');
});

it('rejects duplicate billplz_account_id', function () {
    Tenant::factory()->create(['billplz_account_id' => 'acct_dup']);
    $this->withHeader('Authorization', 'Bearer '.serviceToken())
        ->postJson('/v1/tenants', ['name' => 'B', 'billplz_account_id' => 'acct_dup'])
        ->assertStatus(422);
});
