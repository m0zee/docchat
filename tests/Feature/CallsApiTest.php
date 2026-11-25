<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Contracts\SpeechToText;
use App\Enums\ApiAbility;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Exceptions\SpeechException;
use App\Models\ApiKey;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use App\Services\Calls\CallAgent;
use App\Services\Calls\CallSettings;
use App\Services\Widget\WidgetSettings;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;
use Tests\Support\FakeSpeechToText;

beforeEach(function () {
    config(['app.url' => 'https://docchat.test', 'docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->chat = new FakeChatProvider(['We open at **9** [1].']);
    app()->instance(ChatProvider::class, $this->chat);
    $this->stt = new FakeSpeechToText('When do you open?');
    app()->instance(SpeechToText::class, $this->stt);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $this->workspace->update(['name' => 'Harbor Library']);
    $document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($document)->create(['page_number' => 2, 'embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    $this->key = WidgetSettings::rotateKey($this->workspace, ['https://shop.example.com']);
    $this->visitor = 'w'.str_repeat('b2', 18);
});

function startWidgetCall(): array
{
    $response = test()->withToken(test()->key)
        ->withHeader('Origin', 'https://shop.example.com')
        ->postJson('/api/v1/calls', ['external_conversation_id' => test()->visitor])
        ->assertCreated();

    test()->flushHeaders();

    return $response->json('data');
}

function callTurn(string $token, array $body = [])
{
    return test()->withToken($token)->post('/api/v1/call/turns', $body, ['Accept' => 'application/json']);
}

test('a website visitor starts a call and hears the greeting with an AI disclosure', function () {
    $data = startWidgetCall();

    expect($data['token'])->toStartWith('dc_call_')
        ->and($data['greeting'])->toContain('Harbor Library')->toContain('automated assistant')
        ->and($data['max_seconds'])->toBe(600);

    $call = Call::query()->withoutGlobalScopes()->find($data['call_id']);
    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->transport)->toBe(CallTransport::Web)
        ->and($call->status)->toBe(CallStatus::InProgress)
        ->and($call->token_hash)->not->toBe($data['token'])
        ->and($call->conversation->channel)->toBe(ConversationChannel::Voice)
        ->and($call->conversation->messages()->first()->content)->toBe($data['greeting']);
});

test('the widget tells whether calls are on, and the owner can turn them off', function () {
    $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')->getJson('/api/v1/widget')
        ->assertJsonPath('data.calls', true);

    CallSettings::save($this->workspace, ['widget_calls' => false]);

    $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')->getJson('/api/v1/widget')
        ->assertJsonPath('data.calls', false);
    $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')
        ->postJson('/api/v1/calls', ['external_conversation_id' => $this->visitor])
        ->assertForbidden()->assertJsonPath('error.code', 'calls_off');
});

test('a widget call needs an allowed site and a visitor id', function () {
    $this->withToken($this->key)->withHeader('Origin', 'https://evil.example.net')
        ->postJson('/api/v1/calls', ['external_conversation_id' => $this->visitor])->assertForbidden();
    $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')
        ->postJson('/api/v1/calls', [])->assertStatus(422);
});

test('speech is transcribed and answered from the documents, ready to read aloud', function () {
    $data = startWidgetCall();

    $response = callTurn($data['token'], ['audio' => UploadedFile::fake()->createWithContent('speech.webm', str_repeat('a', 2048))])
        ->assertOk()
        ->assertJsonPath('data.heard', 'When do you open?')
        ->assertJsonPath('data.reply', 'We open at 9.')
        ->assertJsonPath('data.ended', false)
        ->assertJsonPath('data.citations.0.title', 'Hours')
        ->assertJsonPath('data.citations.0.page', 2);

    expect($this->stt->calls)->toHaveCount(1)
        ->and($this->stt->calls[0]['filename'])->toBe('speech.webm');

    // The transcript keeps the written answer with its sources.
    $call = Call::query()->withoutGlobalScopes()->find($data['call_id']);
    $messages = $call->conversation->messages()->get();
    expect($messages->pluck('role')->all())->toBe([MessageRole::Assistant, MessageRole::User, MessageRole::Assistant])
        ->and($messages[2]->content)->toBe('We open at **9** [1].')
        ->and($call->turns)->toBe(1);
});

test('typed text works without speech recognition', function () {
    $data = startWidgetCall();

    callTurn($data['token'], ['text' => 'When do you open?'])->assertOk()->assertJsonPath('data.reply', 'We open at 9.');

    expect($this->stt->calls)->toBe([]);
});

test('silence is asked about once, then the agent hangs up', function () {
    $data = startWidgetCall();

    callTurn($data['token'])->assertJsonPath('data.reply', CallAgent::NOT_HEARD)->assertJsonPath('data.ended', false);
    callTurn($data['token'])->assertJsonPath('data.reply', CallAgent::SILENT_GOODBYE)->assertJsonPath('data.ended', true);

    callTurn($data['token'], ['text' => 'hello?'])->assertStatus(409)->assertJsonPath('error.code', 'call_ended');
    expect(Call::query()->withoutGlobalScopes()->find($data['call_id'])->end_reason)->toBe('silence');
});

test('whisper filler on silent audio counts as silence', function () {
    $this->stt->text = 'Thanks for watching!';
    $data = startWidgetCall();

    callTurn($data['token'], ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'xx')])
        ->assertJsonPath('data.reply', CallAgent::NOT_HEARD);
    expect($this->chat->calls)->toBe([]);
});

test('saying goodbye ends the call', function () {
    $data = startWidgetCall();

    callTurn($data['token'], ['text' => 'No thanks, bye!'])
        ->assertJsonPath('data.reply', CallAgent::GOODBYE)
        ->assertJsonPath('data.ended', true);

    expect($this->chat->calls)->toBe([])
        ->and(Call::query()->withoutGlobalScopes()->find($data['call_id'])->status)->toBe(CallStatus::Completed);
});

test('a long question that mentions bye is still answered', function () {
    expect(CallAgent::isGoodbye('what do I do if I want to say bye to my membership before it ends'))->toBeFalse()
        ->and(CallAgent::isGoodbye("That's all, thank you"))->toBeTrue()
        ->and(CallAgent::isOptOut('please stop calling me'))->toBeTrue()
        ->and(CallAgent::isOptOut('when do you stop lending DVDs'))->toBeFalse();
});

test('a not-found answer offers the handoff line', function () {
    CallSettings::save($this->workspace, ['handoff' => 'You can email help@harbor.test.']);
    Chunk::query()->update(['embedding' => FakeEmbeddingProvider::withSimilarity(0.1)]);
    $data = startWidgetCall();

    callTurn($data['token'], ['text' => 'Do you sell bikes?'])
        ->assertJsonPath('data.refused', true)
        ->assertJsonPath('data.reply', "I couldn't find that in our documents. You can email help@harbor.test. Is there anything else I can help with?");
});

test('speech recognition errors keep the call going', function () {
    $this->stt->throw = SpeechException::busy();
    $data = startWidgetCall();

    callTurn($data['token'], ['audio' => UploadedFile::fake()->createWithContent('speech.webm', 'xx')])
        ->assertOk()
        ->assertJsonPath('data.error', 'speech_busy')
        ->assertJsonPath('data.ended', false);
});

test('each workspace has a daily speech recognition limit', function () {
    config(['docchat.voice.stt.daily_limit_per_workspace' => 1]);
    $data = startWidgetCall();

    callTurn($data['token'], ['audio' => UploadedFile::fake()->createWithContent('a.webm', 'xx')])->assertJsonPath('data.error', null);
    callTurn($data['token'], ['audio' => UploadedFile::fake()->createWithContent('a.webm', 'xx')])->assertJsonPath('data.error', 'speech_busy');

    expect($this->stt->calls)->toHaveCount(1);
});

test('the turn limit ends the call', function () {
    config(['docchat.calls.max_turns' => 1]);
    $data = startWidgetCall();

    callTurn($data['token'], ['text' => 'When do you open?'])
        ->assertJsonPath('data.ended', true)
        ->assertJsonPath('data.reply', 'We open at 9. '.CallAgent::TIME_UP);
});

test('hanging up ends the call and its token', function () {
    $data = startWidgetCall();

    $this->withToken($data['token'])->postJson('/api/v1/call/end')->assertOk();
    $this->withToken($data['token'])->postJson('/api/v1/call/end')->assertStatus(409);

    $call = Call::query()->withoutGlobalScopes()->find($data['call_id']);
    expect($call->status)->toBe(CallStatus::Completed)->and($call->duration_seconds)->toBeInt();
});

test('call tokens are checked', function () {
    $this->withToken('dc_call_'.str_repeat('x', 40))->postJson('/api/v1/call/turns', ['text' => 'hi'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_call');
    $this->withToken($this->key)->postJson('/api/v1/call/turns', ['text' => 'hi'])->assertUnauthorized();
});

test('an outbound call link is answered, then talks in the contact\'s workspace', function () {
    $contact = CallContact::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Sara Khan', 'email' => 'sara@example.com', 'consent_at' => now(), 'consent_note' => 'asked']);
    $call = Call::query()->create([
        'workspace_id' => $this->workspace->id,
        'contact_id' => $contact->id,
        'direction' => CallDirection::Outbound,
        'transport' => CallTransport::Web,
        'status' => CallStatus::Waiting,
        'purpose' => 'your library card renewal',
        'expires_at' => now()->addDay(),
    ]);
    $token = $call->issueToken();

    callTurn($token, ['text' => 'hi'])->assertStatus(409)->assertJsonPath('error.code', 'call_not_started');

    $this->withToken($token)->postJson('/api/v1/call/answer')->assertOk()
        ->assertJsonPath('data.purpose', 'your library card renewal')
        ->assertJsonPath('data.greeting', 'Hi Sara, this is an automated assistant calling for Harbor Library about your library card renewal. I can answer your questions from our documents. What would you like to know? If you would rather not get these calls, just say stop calling.');

    callTurn($token, ['text' => 'Please stop calling me.'])->assertJsonPath('data.reply', CallAgent::OPTED_OUT)->assertJsonPath('data.ended', true);

    expect($contact->refresh()->opted_out_at)->not->toBeNull()
        ->and($call->refresh()->end_reason)->toBe('opted_out');
});

test('an expired call link is refused', function () {
    $call = Call::query()->create([
        'workspace_id' => $this->workspace->id,
        'direction' => CallDirection::Outbound,
        'transport' => CallTransport::Web,
        'status' => CallStatus::Waiting,
        'expires_at' => now()->subMinute(),
    ]);

    $this->withToken($call->issueToken())->postJson('/api/v1/call/answer')->assertStatus(410);
});

test('the call link page shows who calls and lets the contact opt out', function () {
    $contact = CallContact::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Sara', 'consent_at' => now(), 'email' => 's@example.com']);
    $call = Call::query()->create([
        'workspace_id' => $this->workspace->id,
        'contact_id' => $contact->id,
        'direction' => CallDirection::Outbound,
        'transport' => CallTransport::Web,
        'status' => CallStatus::Waiting,
        'purpose' => 'your card',
        'expires_at' => now()->addDay(),
    ]);
    $token = $call->issueToken();

    $this->get('/call/'.$token)->assertOk()->assertSee('Harbor Library would like to talk')->assertSee('About: your card')->assertSee('voice-call.js');
    $this->get('/call/dc_call_nope')->assertOk()->assertSee('This call link has ended');

    $this->post('/call/'.$token.'/opt-out')->assertRedirect();
    expect($contact->refresh()->opted_out_at)->not->toBeNull()
        ->and($call->refresh()->status)->toBe(CallStatus::Canceled);
    $this->get('/call/'.$token)->assertSee('You will not be called again');
    $this->get('/call/'.$token)->assertSee('This call link has ended');
});

test('a live key can start a call too', function () {
    [, $live] = ApiKey::issue($this->workspace, 'app', [ApiAbility::Ask]);

    $this->withToken($live)->postJson('/api/v1/calls')->assertCreated()->assertJsonStructure(['data' => ['token', 'greeting']]);
});

test('the call script is served like the widget', function () {
    $response = $this->get('/voice-call.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    $script = $response->getContent();

    expect($script)->toContain('DocChatVoiceCall')
        ->and(strlen($script))->toBeLessThan(30_000)
        ->and(preg_match('/[^\x00-\x7F]/', $script))->toBe(0);
    $this->get('/voice-call.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});
