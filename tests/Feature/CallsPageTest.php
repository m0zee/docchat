<?php

use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\WorkspaceRole;
use App\Jobs\PlaceTwilioCallJob;
use App\Mail\CallLinkMail;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\User;
use App\Services\Calls\CallSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.url' => 'https://docchat.test', 'docchat.calls.calling_hours' => '00:00-23:59']);
    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $this->actingAs($this->owner);
});

function consentingContact(array $attributes = []): CallContact
{
    return CallContact::query()->create($attributes + [
        'workspace_id' => test()->workspace->id,
        'name' => 'Sara Khan',
        'phone' => '+923001234567',
        'email' => 'sara@example.com',
        'consent_at' => now(),
        'consent_note' => 'asked',
    ]);
}

test('owners see the calls page, members do not', function () {
    $this->get('/workspace/calls')->assertOk()->assertSee('Start a test call')->assertSee('Contacts');

    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->actingAs($member)->get('/workspace/calls')->assertForbidden();
});

test('a contact is added with their consent, in E.164', function () {
    Livewire::test('pages::workspace.calls')
        ->set('contactName', 'Sara')
        ->set('contactPhone', '0092 300 123-4567')
        ->set('contactConsent', true)
        ->call('addContact')
        ->assertHasErrors('contactConsentNote')
        ->set('contactConsentNote', 'Asked for a call on the website')
        ->call('addContact')
        ->assertHasNoErrors();

    $contact = CallContact::query()->sole();
    expect($contact->phone)->toBe('+923001234567')
        ->and($contact->consent_at)->not->toBeNull()
        ->and($contact->mayBeCalled())->toBeTrue();

    Livewire::test('pages::workspace.calls')
        ->set('contactName', 'Again')->set('contactPhone', '+923001234567')->call('addContact')->assertHasErrors('contactPhone')
        ->set('contactName', 'Bad')->set('contactPhone', '12345')->call('addContact')->assertHasErrors('contactPhone');
});

test('a call link is created and emailed', function () {
    Mail::fake();
    $contact = consentingContact();

    $component = Livewire::test('pages::workspace.calls')
        ->set('callContactId', $contact->id)
        ->set('callTransport', 'web')
        ->set('callPurpose', 'your card renewal')
        ->call('startCall')
        ->assertHasNoErrors();

    $call = Call::query()->sole();
    expect($call->transport)->toBe(CallTransport::Web)
        ->and($call->status)->toBe(CallStatus::Waiting)
        ->and($call->expires_at->isFuture())->toBeTrue()
        ->and($component->get('createdLink'))->toStartWith('https://docchat.test/call/dc_call_');

    Mail::assertQueued(CallLinkMail::class, fn (CallLinkMail $mail) => $mail->hasTo('sara@example.com') && $mail->url === $component->get('createdLink'));
});

test('only contacts who agreed can be called', function () {
    $contact = consentingContact(['consent_at' => null]);
    $optedOut = consentingContact(['phone' => '+923009999999', 'opted_out_at' => now()]);

    foreach ([$contact, $optedOut] as $who) {
        Livewire::test('pages::workspace.calls')
            ->set('callContactId', $who->id)->set('callPurpose', 'x')->call('startCall')
            ->assertHasErrors('callContactId');
    }

    expect(Call::query()->count())->toBe(0);
});

test('a contact from another workspace cannot be called', function () {
    // The other user's own workspace (a second one would shift the id sequences other tests rely on).
    $other = User::factory()->create()->currentWorkspace();
    $stranger = CallContact::query()->create(['workspace_id' => $other->id, 'name' => 'X', 'phone' => '+15550000000', 'consent_at' => now()]);

    Livewire::test('pages::workspace.calls')
        ->set('callContactId', $stranger->id)->set('callPurpose', 'x')->call('startCall')
        ->assertHasErrors('callContactId');
});

test('a phone call needs the Twilio setup, then is queued', function () {
    Queue::fake();
    $contact = consentingContact();

    Livewire::test('pages::workspace.calls')
        ->set('callContactId', $contact->id)->set('callTransport', 'phone')->set('callPurpose', 'x')->call('startCall')
        ->assertHasErrors('callTransport');

    config(['docchat.calls.twilio.account_sid' => 'AC1', 'docchat.calls.twilio.auth_token' => 't', 'docchat.calls.twilio.from_number' => '+15005550006']);

    Livewire::test('pages::workspace.calls')
        ->set('callContactId', $contact->id)->set('callTransport', 'phone')->set('callPurpose', 'x')->call('startCall')
        ->assertHasNoErrors();

    $call = Call::query()->sole();
    expect($call->status)->toBe(CallStatus::Queued)->and($call->to_number)->toBe('+923001234567');
    Queue::assertPushed(PlaceTwilioCallJob::class, fn ($job) => $job->callId === $call->id);
});

test('the phone number is saved once per server', function () {
    Livewire::test('pages::workspace.calls')
        ->set('phoneNumber', '+1 (500) 555-0006')
        ->set('greeting', 'Hello, this is the Harbor assistant, an automated helper.')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(CallSettings::for($this->workspace->refresh())['phone_number'])->toBe('+15005550006')
        ->and(CallSettings::workspaceForNumber('+15005550006')?->id)->toBe($this->workspace->id)
        ->and(CallSettings::greeting($this->workspace))->toStartWith('Hello, this is the Harbor');

    $other = User::factory()->create();
    $this->actingAs($other);
    Livewire::test('pages::workspace.calls')->set('phoneNumber', '+15005550006')->call('saveSettings')->assertHasErrors('phoneNumber');
});

test('a test call gives the browser a call token', function () {
    Livewire::test('pages::workspace.calls')
        ->call('testCall')
        ->assertDispatched('docchat-test-call', fn (string $name, array $params) => str_starts_with($params['token'], 'dc_call_'));

    expect(Call::query()->sole()->created_by_user_id)->toBe($this->owner->id);
});

test('waiting calls can be canceled', function () {
    Mail::fake();
    $contact = consentingContact(['email' => null]);
    Livewire::test('pages::workspace.calls')->set('callContactId', $contact->id)->set('callPurpose', 'x')->call('startCall');
    $call = Call::query()->sole();

    Livewire::test('pages::workspace.calls')->call('cancelCall', $call->id);

    expect($call->refresh()->status)->toBe(CallStatus::Canceled);
    Mail::assertNothingSent();
});
