<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Jobs\PlaceTwilioCallJob;
use App\Mail\CallLinkMail;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use App\Services\Calls\CallAgent;
use App\Services\Calls\OutboundCalls;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config([
        'app.url' => 'https://docchat.test',
        'docchat.cache.answers' => false,
        'docchat.rag.similarity_threshold' => 0.5,
        'docchat.calls.calling_hours' => '09:00-20:00',
        'docchat.calls.timezone' => 'Asia/Karachi',
        'docchat.calls.twilio.account_sid' => 'AC123',
        'docchat.calls.twilio.auth_token' => 'secret-token',
        'docchat.calls.twilio.from_number' => '+15005550006',
    ]);
    // 25 Nov 2026, 12:00 in Karachi.
    Carbon::setTestNow(Carbon::parse('2026-11-25 07:00:00', 'UTC'));
    Queue::fake();
    Mail::fake();

    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->chat = new FakeChatProvider(['We have free parking behind the building [1].']);
    app()->instance(ChatProvider::class, $this->chat);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $this->workspace->update(['name' => 'Harbor Clinic']);
    $this->actingAs($this->owner);

    $this->contact = CallContact::query()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Sara Khan',
        'phone' => '+923001234567',
        'email' => 'sara@example.com',
        'consent_at' => now(),
        'consent_note' => 'Booked online and asked for a reminder call',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function scheduleForm(array $values = [])
{
    $page = Livewire::test('pages::conversations.index')->set('tab', 'calls');

    foreach ($values + [
        'contactMode' => 'existing',
        'contactId' => test()->contact->id,
        'transport' => 'phone',
        'when' => 'later',
        'date' => '2026-11-26',
        'time' => '10:30',
        'timezone' => 'Asia/Karachi',
        'purpose' => 'your appointment on Tuesday',
        'agenda' => "1. Can you still make Tuesday at 3 pm?\n- Do you need parking?\n\n",
    ] as $key => $value) {
        $page->set($key, $value);
    }

    return $page->call('schedule');
}

test('a phone call is scheduled in the person\'s time zone, with an agenda', function () {
    scheduleForm()->assertHasNoErrors()->assertSee('Sara Khan')->assertSee('Thu 26 Nov, 10:30');

    $call = Call::query()->sole();
    expect($call->status)->toBe(CallStatus::Scheduled)
        ->and($call->transport)->toBe(CallTransport::Phone)
        ->and($call->scheduled_at->utc()->toDateTimeString())->toBe('2026-11-26 05:30:00')
        ->and($call->timezone)->toBe('Asia/Karachi')
        ->and($call->agendaPoints())->toBe(['Can you still make Tuesday at 3 pm?', 'Do you need parking?'])
        ->and($call->to_number)->toBe('+923001234567');

    Queue::assertNothingPushed();
});

test('scheduled times must be in the future, not too far ahead, and inside calling hours for phone calls', function () {
    scheduleForm(['date' => '2026-11-24'])->assertHasErrors('date');
    scheduleForm(['date' => '2027-03-01'])->assertHasErrors('date');
    // 22:00 in Karachi is outside 09:00-20:00.
    scheduleForm(['time' => '22:00'])->assertHasErrors('date');
    // 22:00 in London is 03:00 in Karachi: still outside.
    scheduleForm(['time' => '22:00', 'timezone' => 'Europe/London'])->assertHasErrors('date');
    // A link call can be at any hour.
    scheduleForm(['time' => '22:00', 'transport' => 'web'])->assertHasNoErrors();
    scheduleForm(['date' => 'tomorrow'])->assertHasErrors('date');
    scheduleForm(['agenda' => implode("\n", array_fill(0, 9, 'A point'))])->assertHasErrors('agenda');

    expect(Call::query()->count())->toBe(1);
});

test('only people who agreed to be called can be scheduled', function () {
    $this->contact->optOut();
    scheduleForm()->assertHasErrors('contactId');

    $noConsent = CallContact::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'No Consent', 'phone' => '+923009999999']);
    scheduleForm(['contactId' => $noConsent->id])->assertHasErrors('contactId');

    expect(Call::query()->count())->toBe(0);
});

test('the owner adds a new person by phone number and schedules them on the same form', function () {
    scheduleForm(['contactMode' => 'new', 'contactId' => null, 'newName' => 'Ali', 'newPhone' => '0300 1112222', 'newConsentNote' => 'Asked on the phone'])
        ->assertHasErrors('newConsent');
    scheduleForm(['contactMode' => 'new', 'contactId' => null, 'newName' => 'Ali', 'newPhone' => '0300 1112222', 'newConsent' => true, 'newConsentNote' => 'Asked on the phone'])
        ->assertHasErrors('newPhone');
    scheduleForm(['contactMode' => 'new', 'contactId' => null, 'newName' => 'Ali', 'newPhone' => '', 'newConsent' => true, 'newConsentNote' => 'Asked on the phone'])
        ->assertHasErrors('newPhone');
    scheduleForm(['contactMode' => 'new', 'contactId' => null, 'newName' => 'Sara again', 'newPhone' => '+923001234567', 'newConsent' => true, 'newConsentNote' => 'x'])
        ->assertHasErrors('newPhone');

    scheduleForm(['contactMode' => 'new', 'contactId' => null, 'newName' => 'Ali', 'newPhone' => '+92 300 111 2222', 'newConsent' => true, 'newConsentNote' => 'Asked on the phone, 25 Nov'])
        ->assertHasNoErrors();

    $ali = CallContact::query()->where('name', 'Ali')->sole();
    expect($ali->phone)->toBe('+923001112222')
        ->and($ali->mayBeCalled())->toBeTrue()
        ->and($ali->consent_note)->toBe('Asked on the phone, 25 Nov')
        ->and(Call::query()->sole()->contact_id)->toBe($ali->id);
});

test('a new person can get just an invitation link, with no phone number', function () {
    $page = scheduleForm(['contactMode' => 'new', 'contactId' => null, 'newName' => 'Guest', 'newPhone' => '', 'newEmail' => '', 'newConsent' => true, 'newConsentNote' => 'Asked in our shop', 'transport' => 'web', 'when' => 'now'])
        ->assertHasNoErrors();

    $call = Call::query()->sole();
    expect($call->transport)->toBe(CallTransport::Web)
        ->and($call->status)->toBe(CallStatus::Waiting)
        ->and($call->scheduled_at)->toBeNull()
        ->and($page->get('createdLink'))->toStartWith('https://docchat.test/call/dc_call_');

    Mail::assertNothingOutgoing();
});

test('a call now goes out at once', function () {
    scheduleForm(['when' => 'now'])->assertHasNoErrors();

    expect(Call::query()->sole()->status)->toBe(CallStatus::Queued);
    Queue::assertPushed(PlaceTwilioCallJob::class);
});

test('the scheduler rings scheduled phone calls when their time comes, once', function () {
    scheduleForm()->assertHasNoErrors();
    $call = Call::query()->sole();

    $this->artisan('docchat:start-scheduled-calls')->assertSuccessful();
    Queue::assertNothingPushed();

    Carbon::setTestNow(Carbon::parse('2026-11-26 05:30:20', 'UTC'));
    $this->artisan('docchat:start-scheduled-calls')->expectsOutputToContain('Started 1 phone calls')->assertSuccessful();
    $this->artisan('docchat:start-scheduled-calls')->expectsOutputToContain('Started 0 phone calls')->assertSuccessful();

    Queue::assertPushed(PlaceTwilioCallJob::class, 1);
    Queue::assertPushed(PlaceTwilioCallJob::class, fn ($job) => $job->callId === $call->id);
    expect($call->refresh()->status)->toBe(CallStatus::Queued);
});

test('a scheduled call is skipped if the scheduler was down for too long', function () {
    scheduleForm()->assertHasNoErrors();

    Carbon::setTestNow(Carbon::parse('2026-11-26 07:30:00', 'UTC'));
    app(OutboundCalls::class)->startDue();

    $call = Call::query()->sole();
    expect($call->status)->toBe(CallStatus::Canceled)->and($call->end_reason)->toBe('missed_schedule');
    Queue::assertNothingPushed();
});

test('the scheduler is registered to run every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('docchat:start-scheduled-calls')->assertSuccessful();
});

test('a scheduled call link opens at its time and is emailed again then', function () {
    $page = scheduleForm(['transport' => 'web', 'time' => '18:00'])->assertHasNoErrors();
    $call = Call::query()->sole();
    $link = $page->get('createdLink');
    $token = str($link)->after('/call/')->toString();

    expect($call->status)->toBe(CallStatus::Waiting)
        ->and($call->link_token)->toBe($token)
        ->and($call->getRawOriginal('link_token'))->not->toContain($token)
        ->and($call->expires_at->gt($call->scheduled_at))->toBeTrue();

    Mail::assertQueued(CallLinkMail::class, fn (CallLinkMail $mail) => $mail->hasTo('sara@example.com') && ! $mail->reminder && $mail->url === $link);
    $html = (new CallLinkMail($call->load('workspace', 'contact'), $link))->render();
    expect($html)->toContain('Thursday 26 November, 18:00')->toContain('Asia/Karachi');

    // Too early: the page shows when, and the call can't start.
    $this->get('/call/'.$token)->assertOk()->assertSee('Your call is scheduled for')->assertDontSee('data-test="start-call"', false);
    $this->withToken($token)->postJson('/api/v1/call/answer')->assertStatus(425)->assertJsonPath('error.code', 'call_too_early');

    // Five minutes before, it opens.
    Carbon::setTestNow(Carbon::parse('2026-11-26 12:56:00', 'UTC'));
    $this->get('/call/'.$token)->assertSee('data-test="start-call"', false);

    // At the time, the reminder goes out with the same link, once.
    Carbon::setTestNow(Carbon::parse('2026-11-26 13:00:30', 'UTC'));
    app(OutboundCalls::class)->startDue();
    app(OutboundCalls::class)->startDue();

    Mail::assertQueued(CallLinkMail::class, fn (CallLinkMail $mail) => $mail->reminder && $mail->url === $link);
    Mail::assertQueued(CallLinkMail::class, 2);
    expect($call->refresh()->link_token)->toBeNull();

    $this->withToken($token)->postJson('/api/v1/call/answer')->assertOk()
        ->assertJsonPath('data.greeting', fn (string $greeting) => str_contains($greeting, 'virtual assistant') && str_ends_with($greeting, 'Can you still make Tuesday at 3 pm?'));
});

test('owners cancel and move scheduled calls', function () {
    scheduleForm()->assertHasNoErrors();
    $call = Call::query()->sole();

    Livewire::test('pages::conversations.index')->set('tab', 'calls')
        ->call('editSchedule', $call->id)
        ->assertSet('rescheduleDate', '2026-11-26')
        ->assertSet('rescheduleTime', '10:30')
        ->set('rescheduleTime', '23:00')->call('saveSchedule')->assertHasErrors('rescheduleDate')
        ->set('rescheduleDate', '2026-11-27')->set('rescheduleTime', '11:00')->call('saveSchedule')->assertHasNoErrors();

    expect($call->refresh()->scheduled_at->utc()->toDateTimeString())->toBe('2026-11-27 06:00:00');

    Livewire::test('pages::conversations.index')->set('tab', 'calls')->call('cancelCall', $call->id);
    expect($call->refresh()->status)->toBe(CallStatus::Canceled);

    Carbon::setTestNow(Carbon::parse('2026-11-27 06:00:30', 'UTC'));
    app(OutboundCalls::class)->startDue();
    Queue::assertNothingPushed();
});

test('the Calls settings page can cancel a scheduled call too', function () {
    scheduleForm()->assertHasNoErrors();
    $call = Call::query()->sole();

    Livewire::test('pages::workspace.calls')->assertSee('Scheduled')->call('cancelCall', $call->id);
    expect($call->refresh()->status)->toBe(CallStatus::Canceled);
});

// The agent follows the agenda

function agendaCall(array $agenda, float $similarity = 0.1): Call
{
    $document = Document::factory()->ready()->for(test()->owner)->create(['title' => 'Visitor guide']);
    Chunk::factory()->for($document)->create(['page_number' => 1, 'embedding' => FakeEmbeddingProvider::withSimilarity($similarity)]);

    return Call::query()->create([
        'workspace_id' => test()->workspace->id,
        'contact_id' => test()->contact->id,
        'direction' => 'outbound',
        'transport' => CallTransport::Web,
        'status' => CallStatus::Waiting,
        'purpose' => 'your appointment',
        'agenda' => $agenda,
    ])->load('workspace', 'contact');
}

test('the agent asks each agenda point in order, then asks if there is anything else', function () {
    $call = agendaCall(['Can you still make Tuesday at 3 pm?', 'Do you need parking?']);
    $agent = app(CallAgent::class);

    $opening = $agent->begin($call);
    expect($opening)->toContain('virtual assistant')
        ->toContain('about your appointment')
        ->toContain('stop calling')
        ->toEndWith('Can you still make Tuesday at 3 pm?');

    // Nothing in the documents matches "yes": acknowledge, then the next point. No model call.
    $reply = $agent->respond($call, 'Yes, that works.');
    expect($reply->text)->toBe('Got it, thanks. Do you need parking?')->and($reply->end)->toBeFalse()->and($reply->refused)->toBeFalse()
        ->and($this->chat->calls)->toBe([]);

    // "No thanks" answers the question; it doesn't hang up while the agenda is open.
    $reply = $agent->respond($call->refresh(), 'No thanks.');
    expect($reply->end)->toBeFalse()->and($reply->text)->toBe('Okay, thank you. Is there anything else I can help with?');

    $reply = $agent->respond($call->refresh(), 'No, bye.');
    expect($reply->end)->toBeTrue();

    $transcript = $call->refresh()->conversation->messages->pluck('content')->all();
    expect($transcript)->toContain('Got it, thanks. Do you need parking?')
        ->and($transcript)->toContain('Yes, that works.')
        ->and($call->agenda_step)->toBe(3);
});

test('questions in between are answered from the documents, then the agenda goes on', function () {
    $call = agendaCall(['Can you still make Tuesday at 3 pm?', 'Shall we send a reminder?'], similarity: 0.9);
    $agent = app(CallAgent::class);
    $agent->begin($call);

    $reply = $agent->respond($call, 'Yes. Is there parking?');

    expect($reply->text)->toBe('We have free parking behind the building. Shall we send a reminder?')
        ->and($this->chat->calls[0][0]['content'])->toContain('do not ask the caller a question yourself');
    expect($call->refresh()->conversation->messages->last()->content)->toBe('We have free parking behind the building [1]. Shall we send a reminder?');
});

test('calls without an agenda work as before', function () {
    $call = agendaCall([]);
    $agent = app(CallAgent::class);

    expect($agent->begin($call))->toContain('What would you like to know?');
    expect($agent->respond($call, 'No thanks.')->end)->toBeTrue();
});
