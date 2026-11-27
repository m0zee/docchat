<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\ConversationChannel;
use App\Jobs\PlaceTwilioCallJob;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use App\Services\Calls\CallingHours;
use App\Services\Calls\CallSettings;
use App\Services\Twilio\TwilioClient;
use App\Services\Twilio\TwilioSignature;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config([
        'app.url' => 'https://docchat.test',
        'docchat.rag.similarity_threshold' => 0.5,
        'docchat.cache.answers' => false,
        'docchat.calls.twilio.account_sid' => 'AC123',
        'docchat.calls.twilio.auth_token' => 'secret-token',
        'docchat.calls.twilio.from_number' => '+15005550006',
        'docchat.calls.calling_hours' => '00:00-23:59',
    ]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->chat = new FakeChatProvider(['Open **9** to 5 [1].']);
    app()->instance(ChatProvider::class, $this->chat);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $this->workspace->update(['name' => 'Harbor & Co']);
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    $document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($document)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
});

function twilioPost(string $path, array $params, ?string $signature = null)
{
    $url = 'https://docchat.test/'.$path;

    return test()->call('POST', '/'.$path, $params, [], [], [
        'HTTP_X_TWILIO_SIGNATURE' => $signature ?? TwilioSignature::sign($url, $params, 'secret-token'),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ]);
}

test('the signature follows Twilio\'s algorithm', function () {
    $params = ['To' => '+1', 'CallSid' => 'CA1', 'From' => '+2'];
    $expected = base64_encode(hash_hmac('sha1', 'https://x.test/a?b=1CallSidCA1From+2To+1', 'tok', true));

    expect(TwilioSignature::sign('https://x.test/a?b=1', $params, 'tok'))->toBe($expected)
        ->and(TwilioSignature::valid('https://x.test/a?b=1', $params, $expected, 'tok'))->toBeTrue()
        ->and(TwilioSignature::valid('https://x.test/a?b=2', $params, $expected, 'tok'))->toBeFalse()
        ->and(TwilioSignature::valid('https://x.test/a?b=1', $params, $expected, ''))->toBeFalse();
});

test('unsigned or wrongly signed webhooks are refused', function () {
    twilioPost('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006'], 'bad')->assertForbidden();

    config(['docchat.calls.twilio.webhook_base_url' => 'https://tunnel.example']);
    twilioPost('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006'])->assertForbidden();
});

test('an inbound call to the workspace number is greeted and listened to', function () {
    $response = twilioPost('twilio/voice/inbound', ['CallSid' => 'CA100', 'From' => '+923001234567', 'To' => '+15005550006', 'CallStatus' => 'ringing'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/xml; charset=utf-8');

    $call = Call::query()->withoutGlobalScopes()->where('provider_sid', 'CA100')->sole();
    $xml = simplexml_load_string($response->getContent());

    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->transport)->toBe(CallTransport::Phone)
        ->and($call->status)->toBe(CallStatus::InProgress)
        ->and($call->from_number)->toBe('+923001234567')
        ->and($call->conversation->channel)->toBe(ConversationChannel::Phone)
        ->and((string) $xml->Gather['input'])->toBe('speech')
        ->and((string) $xml->Gather['action'])->toBe('https://docchat.test/twilio/voice/calls/'.$call->id.'/gather')
        ->and((string) $xml->Gather->Say)->toContain('Harbor & Co')->toContain('virtual assistant');

    // Twilio retrying the same call does not make a second one.
    twilioPost('twilio/voice/inbound', ['CallSid' => 'CA100', 'From' => '+923001234567', 'To' => '+15005550006'])->assertOk();
    expect(Call::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a number no workspace claimed gets a polite hang up', function () {
    $response = twilioPost('twilio/voice/inbound', ['CallSid' => 'CA9', 'To' => '+15005550099'])->assertOk();

    expect($response->getContent())->toContain('<Hangup/>')->toContain('not connected');
});

test('speech results are answered from the documents', function () {
    twilioPost('twilio/voice/inbound', ['CallSid' => 'CA1', 'From' => '+923001234567', 'To' => '+15005550006']);
    $call = Call::query()->withoutGlobalScopes()->sole();

    // The answer is written in a job; meanwhile the caller hears a filler and the hold sound.
    $response = twilioPost('twilio/voice/calls/'.$call->id.'/gather', ['CallSid' => 'CA1', 'SpeechResult' => 'When are you open?', 'Confidence' => '0.9'])->assertOk();
    $xml = simplexml_load_string($response->getContent());
    $next = (string) $xml->Redirect;

    expect((string) $xml->Say)->toBe('Hmm, let me check.')
        ->and((string) $xml->Play)->toBe('https://docchat.test/twilio/voice/hold.wav')
        ->and($next)->toStartWith('https://docchat.test/twilio/voice/calls/'.$call->id.'/reply/')->toEndWith('?attempt=1');

    // Not ready yet: the hold sound again, no second filler.
    $path = substr($next, strlen('https://docchat.test/'));
    $xml = simplexml_load_string(twilioPost($path, ['CallSid' => 'CA1'])->getContent());
    expect((string) $xml->Say)->toBe('')->and((string) $xml->Play)->not->toBe('')->and((string) $xml->Redirect)->toEndWith('?attempt=2');

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $xml = simplexml_load_string(twilioPost($path, ['CallSid' => 'CA1'])->getContent());
    expect((string) $xml->Gather->Say)->toBe('Open nine to five.');

    $response = twilioPost('twilio/voice/calls/'.$call->id.'/gather', ['CallSid' => 'CA1', 'SpeechResult' => 'Goodbye'])->assertOk();
    expect($response->getContent())->toContain('Thanks for calling. Bye for now!')->toContain('<Hangup/>');
});

test('a webhook for one call cannot steer another', function () {
    twilioPost('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006']);
    $call = Call::query()->withoutGlobalScopes()->sole();

    $response = twilioPost('twilio/voice/calls/'.$call->id.'/gather', ['CallSid' => 'CA-other', 'SpeechResult' => 'hi']);

    expect($response->getContent())->toContain('<Hangup/>')->and($this->chat->calls)->toBe([]);
});

test('the status callback stores the final status and duration', function () {
    twilioPost('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006']);
    $call = Call::query()->withoutGlobalScopes()->sole();

    twilioPost('twilio/voice/calls/'.$call->id.'/status', ['CallSid' => 'CA1', 'CallStatus' => 'completed', 'CallDuration' => '73'])->assertNoContent();

    expect($call->refresh()->status)->toBe(CallStatus::Completed)
        ->and($call->duration_seconds)->toBe(73)
        ->and($call->end_reason)->toBe('twilio_completed');
});

function outboundPhoneCall(array $contact = []): Call
{
    $contact = CallContact::query()->create($contact + [
        'workspace_id' => test()->workspace->id,
        'name' => 'Sara',
        'phone' => '+923001234567',
        'consent_at' => now(),
        'consent_note' => 'asked on the website',
    ]);

    return Call::query()->create([
        'workspace_id' => test()->workspace->id,
        'contact_id' => $contact->id,
        'direction' => CallDirection::Outbound,
        'transport' => CallTransport::Phone,
        'status' => CallStatus::Queued,
        'purpose' => 'your card',
    ]);
}

test('an outbound call is placed through Twilio\'s API', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA777', 'status' => 'queued'], 201)]);
    $call = outboundPhoneCall();

    (new PlaceTwilioCallJob($call->id))->handle(app(TwilioClient::class), app(CurrentWorkspace::class));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Calls.json'
        && $request['To'] === '+923001234567'
        && $request['From'] === '+15005550006'
        && $request['Url'] === 'https://docchat.test/twilio/voice/calls/'.$call->id.'/answer'
        && $request['StatusCallback'] === 'https://docchat.test/twilio/voice/calls/'.$call->id.'/status'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret-token')));

    expect($call->refresh()->provider_sid)->toBe('CA777')->and($call->status)->toBe(CallStatus::Ringing);

    // When it is picked up, the agent opens with the purpose and the opt-out line.
    $response = twilioPost('twilio/voice/calls/'.$call->id.'/answer', ['CallSid' => 'CA777', 'CallStatus' => 'in-progress'])->assertOk();
    expect($response->getContent())->toContain('Hi Sara, this is Ava, a virtual assistant calling for Harbor &amp; Co about your card.')->toContain('stop calling');
});

test('outbound calls need consent, no opt-out and the calling hours', function (array $contact, ?string $hours, string $reason) {
    Http::fake();
    if ($hours !== null) {
        config(['docchat.calls.calling_hours' => $hours]);
    }
    $call = outboundPhoneCall($contact);

    dispatch_sync(new PlaceTwilioCallJob($call->id));

    Http::assertNothingSent();
    expect($call->refresh()->status)->toBe(CallStatus::Canceled)->and($call->end_reason)->toBe($reason);
})->with([
    'no consent' => [['consent_at' => null], null, 'no_consent'],
    'opted out' => [['opted_out_at' => now()], null, 'no_consent'],
    'night' => [[], '09:00-09:01', 'outside_calling_hours'],
    'no phone' => [['phone' => null, 'email' => 'x@example.com'], null, 'no_phone_number'],
]);

test('a refused Twilio call is marked failed', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 21219, 'message' => 'The number is unverified.'], 400)]);
    $call = outboundPhoneCall();

    dispatch_sync(new PlaceTwilioCallJob($call->id));

    expect($call->refresh()->status)->toBe(CallStatus::Failed)->and($call->end_reason)->toBe('twilio_refused');
});

test('calling hours can wrap past midnight', function () {
    config(['docchat.calls.calling_hours' => '22:00-02:00', 'docchat.calls.timezone' => 'UTC']);

    expect(CallingHours::allows(Carbon::parse('2026-11-25 23:30', 'UTC')))->toBeTrue()
        ->and(CallingHours::allows(Carbon::parse('2026-11-25 01:00', 'UTC')))->toBeTrue()
        ->and(CallingHours::allows(Carbon::parse('2026-11-25 12:00', 'UTC')))->toBeFalse();
});
