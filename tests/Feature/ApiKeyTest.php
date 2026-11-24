<?php

use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
});

test('keys look like dc_live_ and only a hash is stored', function () {
    [$key, $plain] = ApiKey::issue($this->workspace, 'Server', [ApiAbility::Ask]);

    expect($plain)->toMatch('/^dc_live_[A-Za-z0-9]{40}$/')
        ->and($key->secret_hash)->toBe(hash('sha256', $plain))
        ->and($key->prefix)->toBe(substr($plain, 0, 14))
        ->and(json_encode($key->toArray()))->not->toContain($plain)
        ->and(json_encode($key->toArray()))->not->toContain($key->secret_hash);
});

test('a valid key reaches the API and sees its workspace', function () {
    [, $plain] = ApiKey::issue($this->workspace, 'Server', [ApiAbility::Ask]);

    $this->withToken($plain)->getJson('/api/v1/workspace')
        ->assertOk()
        ->assertJsonPath('data.workspace.id', $this->workspace->id)
        ->assertJsonPath('data.key.abilities', ['ask']);
});

test('the key also works in the X-Api-Key header', function () {
    [, $plain] = ApiKey::issue($this->workspace, 'Server', [ApiAbility::Ask]);

    $this->getJson('/api/v1/workspace', ['X-Api-Key' => $plain])->assertOk();
});

test('missing, invalid and revoked keys get 401 in the API error format', function () {
    [$key, $plain] = ApiKey::issue($this->workspace, 'Server', [ApiAbility::Ask]);

    $this->getJson('/api/v1/workspace')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->withToken('dc_live_'.str_repeat('a', 40))->getJson('/api/v1/workspace')->assertUnauthorized()->assertJsonPath('error.code', 'invalid_key');

    $key->revoke();

    $this->withToken($plain)->getJson('/api/v1/workspace')->assertUnauthorized()->assertJsonPath('error.code', 'revoked_key');
});

test('public widget keys cannot use the secret API', function () {
    [, $plain] = ApiKey::issue($this->workspace, 'Widget', [ApiAbility::Ask], ApiKeyType::Public, allowedOrigins: ['https://example.com']);

    $this->withToken($plain)->getJson('/api/v1/workspace')->assertForbidden()->assertJsonPath('error.code', 'wrong_key_type');
});

test('each key has its own rate limit with Retry-After', function () {
    [, $plain] = ApiKey::issue($this->workspace, 'Slow', [ApiAbility::Ask], rateLimitPerMinute: 2);
    [, $other] = ApiKey::issue($this->workspace, 'Other', [ApiAbility::Ask], rateLimitPerMinute: 2);

    $this->withToken($plain)->getJson('/api/v1/workspace')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/workspace')->assertOk();

    $this->withToken($plain)->getJson('/api/v1/workspace')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'rate_limited');

    $this->withToken($other)->getJson('/api/v1/workspace')->assertOk();
});

test('last_used_at is recorded', function () {
    [$key, $plain] = ApiKey::issue($this->workspace, 'Server', [ApiAbility::Ask]);

    $this->withToken($plain)->getJson('/api/v1/workspace')->assertOk();

    expect($key->fresh()->last_used_at)->not->toBeNull();
});

test('an unknown api route answers in the API error format', function () {
    [, $plain] = ApiKey::issue($this->workspace, 'Server', [ApiAbility::Ask]);

    $this->withToken($plain)->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

test('owners create a key that is shown once, and revoke it', function () {
    $component = Livewire::actingAs($this->owner)->test('pages::workspace.api-keys')
        ->set('name', 'n8n')
        ->set('abilities', ['ask', 'documents:write'])
        ->call('create')
        ->assertHasNoErrors();

    $plain = $component->get('plainKey');
    $key = ApiKey::query()->sole();

    expect($key->abilities)->toBe(['ask', 'documents:write'])
        ->and(ApiKey::findByPlain($plain)?->id)->toBe($key->id);

    $component->call('revoke', $key->id);

    expect($key->fresh()->isRevoked())->toBeTrue();
});

test('members cannot manage keys, and keys of other workspaces cannot be revoked', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    $this->actingAs($member)->get(route('workspace.api-keys'))->assertForbidden();

    [$foreign] = ApiKey::issue(User::factory()->create()->currentWorkspace(), 'Theirs', [ApiAbility::Ask]);
    Livewire::actingAs($this->owner)->test('pages::workspace.api-keys')->call('revoke', $foreign->id);

    expect($foreign->fresh()->isRevoked())->toBeFalse();
});

test('owners cannot give live keys platform abilities', function () {
    Livewire::actingAs($this->owner)->test('pages::workspace.api-keys')
        ->set('name', 'x')
        ->set('abilities', ['channels:link'])
        ->call('create')
        ->assertHasErrors('abilities.0');
});
