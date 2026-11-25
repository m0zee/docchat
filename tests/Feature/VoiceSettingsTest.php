<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Contracts\SpeechToText;
use App\Contracts\TextToSpeech;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Jobs\PlaceTwilioCallJob;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use App\Services\Calls\CallSettings;
use App\Services\Calls\Phrases;
use App\Services\Calls\VoiceProfile;
use App\Services\Twilio\TwilioClient;
use App\Services\Twilio\TwilioSignature;
use App\Services\Twilio\TwiML;
use App\Services\Voice\KokoroTextToSpeech;
use App\Services\Voice\Wav;
use App\Services\Widget\WidgetSettings;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;
use Tests\Support\FakeSpeechToText;

beforeEach(function () {
    config([
        'app.url' => 'https://docchat.test',
        'docchat.rag.similarity_threshold' => 0.5,
        'docchat.cache.answers' => false,
        'docchat.calls.calling_hours' => '00:00-23:59',
        'docchat.calls.twilio.account_sid' => 'AC123',
        'docchat.calls.twilio.auth_token' => 'secret-token',
        'docchat.calls.twilio.from_number' => '+15005550006',
        'docchat.voice.kokoro.url' => null,
    ]);
    Storage::fake('local');
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
});

function voiceCall(): array
{
    $response = test()->withToken(test()->key)
        ->withHeader('Origin', 'https://shop.example.com')
        ->postJson('/api/v1/calls', ['external_conversation_id' => 'v'.str_repeat('c3', 18)])
        ->assertCreated();

    test()->flushHeaders();

    return $response->json('data');
}

function voiceTurn(string $token, array $body = [])
{
    return test()->withToken($token)->post('/api/v1/call/turns', $body, ['Accept' => 'application/json']);
}

function voiceTwilio(string $path, array $params)
{
    $url = 'https://docchat.test/'.$path;

    return test()->call('POST', '/'.$path, $params, [], [], [
        'HTTP_X_TWILIO_SIGNATURE' => TwilioSignature::sign($url, $params, 'secret-token'),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ]);
}

function kokoroOn(): void
{
    config(['docchat.voice.kokoro.url' => 'http://kokoro:8880']);
    Http::fake(['kokoro:8880/*' => Http::response('ID3-fake-mp3', 200, ['Content-Type' => 'audio/mpeg'])]);
}

// Settings

test('the voice profile has natural defaults and saves only valid values', function () {
    $profile = VoiceProfile::for($this->workspace);

    expect($profile['assistant_name'])->toBe('Ava')
        ->and($profile['language'])->toBe('en-US')
        ->and($profile['web_engine'])->toBe('browser')
        ->and($profile['phone_voice'])->toBe('basic')
        ->and($profile['interruptions'])->toBeTrue()
        ->and($profile['voicemail'])->toBe('off');

    VoiceProfile::save($this->workspace, ['assistant_name' => 'Zara', 'language' => 'ur-PK', 'speed' => 9, 'phone_voice' => 'Polly.Nobody', 'nope' => 'x']);
    $profile = VoiceProfile::for($this->workspace->refresh());

    expect($profile['assistant_name'])->toBe('Zara')
        ->and($profile['language'])->toBe('ur-PK')
        ->and($profile['speed'])->toBe(1.3)
        ->and($profile['phone_voice'])->toBe('basic')
        ->and($profile)->not->toHaveKey('nope');

    // Saving the inbound settings keeps the voice.
    CallSettings::save($this->workspace->refresh(), ['greeting' => 'Hello']);
    expect(VoiceProfile::for($this->workspace->refresh())['assistant_name'])->toBe('Zara');
});

test('owners pick the voice and personality on the calls page', function () {
    $this->actingAs($this->owner);

    $this->get('/workspace/calls')->assertOk()->assertSee('Voice and personality')->assertSee('Basic voice (free)');

    Livewire::test('pages::workspace.calls')
        ->set('voice.assistant_name', 'Sam')
        ->set('voice.personality', 'calm')
        ->set('voice.language', 'en-GB')
        ->set('voice.gender', 'male')
        ->set('voice.age', 'mature')
        ->set('voice.speed', '0.95')
        ->set('voice.pitch', '0.95')
        ->set('voice.phone_voice', 'Polly.Brian-Neural')
        ->set('voice.interruptions', false)
        ->set('voice.pace', 'relaxed')
        ->set('voice.background', 'office')
        ->set('voice.voicemail', 'leave_message')
        ->call('saveVoice')
        ->assertHasNoErrors();

    $profile = VoiceProfile::for($this->workspace->refresh());
    expect($profile)->toMatchArray([
        'assistant_name' => 'Sam', 'personality' => 'calm', 'language' => 'en-GB', 'gender' => 'male', 'age' => 'mature',
        'speed' => 0.95, 'pitch' => 0.95, 'phone_voice' => 'Polly.Brian-Neural', 'interruptions' => false,
        'pace' => 'relaxed', 'background' => 'office', 'voicemail' => 'leave_message',
    ]);
});

test('the calls page rejects bad voice values and the self-hosted voice when it is not set up', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.calls')
        ->set('voice.language', 'xx-XX')
        ->set('voice.speed', '3')
        ->set('voice.assistant_name', '<script>')
        ->call('saveVoice')
        ->assertHasErrors(['voice.language', 'voice.speed', 'voice.assistant_name']);

    Livewire::test('pages::workspace.calls')
        ->set('voice.web_engine', 'kokoro')
        ->call('saveVoice')
        ->assertHasErrors('voice.web_engine');
});

test('phone voices follow the language, with costs labeled', function () {
    $this->actingAs($this->owner);

    $page = Livewire::test('pages::workspace.calls')
        ->set('voice.phone_voice', 'Polly.Joanna-Neural')
        ->set('voice.language', 'fr-FR');

    expect($page->get('voice.phone_voice'))->toBe('basic');
    $voices = $page->instance()->phoneVoices;
    expect($voices)->toHaveKeys(['basic', 'Polly.Lea-Neural', 'Polly.Remi-Neural'])
        ->not->toHaveKey('Polly.Joanna-Neural')
        ->and($voices['Polly.Lea-Neural'])->toContain('uses Twilio credit')
        ->and($voices['basic'])->toBe('Basic voice (free)');

    $page->set('voice.language', 'ur-PK');
    expect($page->instance()->phoneVoices['basic'])->toContain('US English');
});

test('the Kokoro preview speaks the sample greeting in the chosen voice', function () {
    $this->actingAs($this->owner);
    kokoroOn();

    $url = Livewire::test('pages::workspace.calls')
        ->set('voice.assistant_name', 'Mia')
        ->set('voice.kokoro_voice', 'af_bella')
        ->call('previewKokoro')
        ->assertHasNoErrors()
        ->effects['returns'][0] ?? null;

    expect($url)->toBe('data:audio/mpeg;base64,'.base64_encode('ID3-fake-mp3'));
    Http::assertSent(fn (Request $request) => $request->url() === 'http://kokoro:8880/v1/audio/speech'
        && $request['voice'] === 'af_bella'
        && $request['model'] === 'kokoro'
        && $request['response_format'] === 'mp3'
        && str_contains($request['input'], 'this is Mia, Harbor Library\'s virtual assistant'));
});

// Wording

test('greetings use the assistant name and always disclose it is an assistant', function () {
    expect(CallSettings::greeting($this->workspace))->toBe("Hi, this is Ava, Harbor Library's virtual assistant. How can I help you today?");

    CallSettings::save($this->workspace, ['greeting' => 'Welcome to Harbor Library!']);
    expect(CallSettings::greeting($this->workspace->refresh()))->toBe("Welcome to Harbor Library! I'm a virtual assistant.");

    CallSettings::save($this->workspace, ['greeting' => 'Hi, I am the library AI.']);
    expect(CallSettings::greeting($this->workspace->refresh()))->toBe('Hi, I am the library AI.');

    CallSettings::save($this->workspace, ['greeting' => null]);
    VoiceProfile::save($this->workspace->refresh(), ['language' => 'ur-PK', 'assistant_name' => 'Zara']);
    expect(CallSettings::greeting($this->workspace->refresh()))->toContain('Zara')->toContain('ورچوئل اسسٹنٹ');
});

test('every language has every line, and each greeting discloses', function () {
    $keys = array_keys((new ReflectionClassConstant(Phrases::class, 'LINES'))->getValue()['en']);

    foreach (Phrases::LANGUAGES as $language) {
        foreach ($keys as $key) {
            expect(Phrases::all($language, $key))->not->toBeEmpty();
        }

        expect(Phrases::discloses(Phrases::get($language, 'greeting', ['name' => 'Ava', 'workspace' => 'X'])))->toBeTrue("{$language} greeting")
            ->and(Phrases::discloses(Phrases::get($language, 'outbound', ['name' => 'Ava', 'workspace' => 'X', 'first' => '', 'about' => ''])))->toBeTrue("{$language} outbound")
            ->and(Phrases::discloses(Phrases::get($language, 'voicemail', ['name' => 'Ava', 'workspace' => 'X', 'about' => ''])))->toBeTrue("{$language} voicemail");
    }

    foreach (VoiceProfile::LANGUAGES as $tag => $language) {
        expect(Phrases::LANGUAGES)->toContain($language['phrases']);
    }
});

test('goodbye and stop calling are understood in other languages', function () {
    expect(Phrases::isGoodbye('Adiós, gracias'))->toBeTrue()
        ->and(Phrases::isGoodbye('Okay, khuda hafiz'))->toBeTrue()
        ->and(Phrases::isGoodbye('ٹھیک ہے، خدا حافظ'))->toBeTrue()
        ->and(Phrases::isOptOut('Bitte nicht mehr anrufen'))->toBeTrue()
        ->and(Phrases::isOptOut('مجھے کال مت کرو'))->toBeTrue()
        ->and(Phrases::isOptOut('When do you open?'))->toBeFalse()
        ->and(Phrases::discloses('Welcome to Harbor!'))->toBeFalse();
});

// Browser calls

test('a browser call gets its voice settings, and answers are worded for speaking', function () {
    VoiceProfile::save($this->workspace, ['gender' => 'male', 'age' => 'young', 'speed' => 1.1, 'personality' => 'cheerful', 'fillers' => true, 'background' => 'cafe', 'pace' => 'quick']);
    $data = voiceCall();

    expect($data['voice'])->toMatchArray([
        'engine' => 'browser', 'lang' => 'en-US', 'gender' => 'male', 'age' => 'young',
        'interruptions' => true, 'end_of_turn_ms' => 700, 'reply_gap_ms' => 0,
        'hold_music' => true, 'background' => 'cafe', 'name' => 'Ava',
    ])->and($data['voice']['rate'])->toBe(1.17)
        ->and($data['voice']['pitch'])->toBe(1.17)
        ->and($data['voice']['fillers'])->toContain('One moment.');

    voiceTurn($data['token'], ['text' => 'When do you open?'])->assertOk()->assertJsonPath('data.reply', 'We open at 9.');

    $system = $this->chat->calls[0][0]['content'];
    expect($system)->toContain('spoken aloud on a voice call')->toContain('You are Ava, a virtual assistant')
        ->toContain('Upbeat and cheerful')->toContain('Reply in the language the caller used.');
});

test('the caller\'s language is detected and the call follows it', function () {
    $this->stt->detected = 'urdu';
    $this->stt->text = 'آپ کب کھلتے ہیں؟';
    $data = voiceCall();
    $audio = UploadedFile::fake()->createWithContent('speech.webm', str_repeat('a', 2000));

    voiceTurn($data['token'], ['audio' => $audio])->assertOk()
        ->assertJsonPath('data.lang', 'ur-PK')
        ->assertJsonPath('data.fillers.0', 'ہمم، ایک سیکنڈ۔');

    // Auto detection: Whisper gets no language hint.
    expect($this->stt->calls[0]['language'])->toBeNull()
        ->and(Call::query()->withoutGlobalScopes()->find($data['call_id'])->language)->toBe('ur');

    // Silence prompts now come in Urdu.
    voiceTurn($data['token'])->assertJsonPath('data.reply', Phrases::get('ur', 'not_heard', [], 1));
});

test('with auto detection off, Whisper is told the language and the call keeps it', function () {
    VoiceProfile::save($this->workspace, ['auto_language' => false, 'language' => 'es-ES']);
    $this->stt->detected = 'english';
    $data = voiceCall();

    expect($data['greeting'])->toBe('Hola, soy Ava, asistente virtual de Harbor Library. ¿En qué puedo ayudarte?')
        ->and($data['voice']['lang'])->toBe('es-ES');

    voiceTurn($data['token'], ['audio' => UploadedFile::fake()->createWithContent('speech.webm', str_repeat('a', 2000))])
        ->assertOk()->assertJsonPath('data.lang', 'es-ES');

    expect($this->stt->calls[0]['language'])->toBe('es')
        ->and($this->chat->calls[0][0]['content'])->toContain('Reply in Spanish.');
});

test('typed Urdu or Hindi switches the call language by its script', function () {
    $data = voiceCall();

    voiceTurn($data['token'], ['text' => 'आप कब खुलते हैं?'])->assertJsonPath('data.lang', 'hi-IN');
});

test('voice answers do not enter the answer cache', function () {
    config(['docchat.cache.answers' => true]);
    $data = voiceCall();

    voiceTurn($data['token'], ['text' => 'When do you open?'])->assertOk();

    expect(DB::table('answer_cache')->count())->toBe(0);
});

test('the self-hosted voice speaks only this call\'s own lines', function () {
    $data = voiceCall();
    $speak = fn (string $text) => test()->withToken($data['token'])->postJson('/api/v1/call/speech', ['text' => $text]);

    // Not set up on the server.
    $speak('Hi')->assertNotFound()->assertJsonPath('error.code', 'voice_unavailable');

    kokoroOn();
    VoiceProfile::save($this->workspace, ['web_engine' => 'kokoro', 'kokoro_voice' => 'am_michael', 'speed' => 1.1]);
    $data = voiceCall();
    $speak = fn (string $text) => test()->withToken($data['token'])->postJson('/api/v1/call/speech', ['text' => $text]);
    expect($data['voice']['engine'])->toBe('kokoro');

    $speak('Read this advert for free.')->assertUnprocessable()->assertJsonPath('error.code', 'not_this_call');

    $response = $speak('Hi, this is Ava, Harbor Library\'s virtual assistant.')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($response->getContent())->toBe('ID3-fake-mp3');
    $speak('One moment.')->assertOk();

    Http::assertSent(fn (Request $request) => $request['voice'] === 'am_michael' && $request['speed'] === 1.1);

    // The goodbye can still be spoken right after the call ended.
    voiceTurn($data['token'], ['text' => 'bye'])->assertJsonPath('data.ended', true);
    $speak('Thanks for calling.')->assertOk();
    voiceTurn($data['token'], ['text' => 'hello'])->assertStatus(409);
});

test('a Kokoro clip is cached, so the same line is not made twice', function () {
    kokoroOn();
    $kokoro = app(KokoroTextToSpeech::class);

    $first = $kokoro->clip('Hello there.', 'af_heart');
    $second = $kokoro->clip('Hello there.', 'af_heart');

    expect($first)->toBe($second)->toMatch('/^[a-f0-9]{64}$/');
    Http::assertSentCount(1);
    Storage::disk('local')->assertExists(KokoroTextToSpeech::path($first));
});

test('Kokoro picks a voice that speaks the call\'s language', function () {
    $profile = array_merge(VoiceProfile::DEFAULTS, ['kokoro_voice' => 'af_heart', 'gender' => 'male', 'language' => 'en-US']);

    expect(VoiceProfile::kokoroVoice($profile))->toBe('af_heart')
        ->and(VoiceProfile::kokoroVoice($profile, 'es'))->toBe('em_alex')
        ->and(VoiceProfile::kokoroVoice($profile, 'hi'))->toBe('hm_omega')
        ->and(VoiceProfile::kokoroVoice($profile, 'ur'))->toBeNull();
});

test('bot voice replies can use the self-hosted voice', function () {
    kokoroOn();
    config(['docchat.voice.replies' => true, 'docchat.voice.tts.driver' => 'kokoro', 'docchat.voice.kokoro.voice' => 'bf_emma']);

    expect(app(TextToSpeech::class))->toBeInstanceOf(KokoroTextToSpeech::class)
        ->and(app(TextToSpeech::class)->speak('Hello.'))->toBe('ID3-fake-mp3');
    Http::assertSent(fn (Request $request) => $request['voice'] === 'bf_emma');
});

// Phone calls

test('the phone line uses the chosen voice, language, speed and pace', function () {
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    VoiceProfile::save($this->workspace->refresh(), ['phone_voice' => 'Polly.Joanna-Neural', 'speed' => 0.9, 'interruptions' => false, 'pace' => 'relaxed']);

    $xml = simplexml_load_string(voiceTwilio('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006'])->getContent());

    expect((string) $xml->Gather['bargeIn'])->toBe('false')
        ->and((string) $xml->Gather['speechTimeout'])->toBe('2')
        ->and((string) $xml->Gather['language'])->toBe('en-US')
        ->and((string) $xml->Gather->Say['voice'])->toBe('Polly.Joanna-Neural')
        ->and((string) $xml->Gather->Say->prosody['rate'])->toBe('90%')
        ->and((string) $xml->Gather->Say->prosody)->toContain('virtual assistant');
});

test('the free basic voice is used for languages it speaks, with the voice\'s gender', function () {
    $basic = fn (array $values) => VoiceProfile::phone(array_merge(VoiceProfile::DEFAULTS, $values));

    expect($basic(['gender' => 'male', 'language' => 'de-DE']))->toBe(['voice' => 'man', 'language' => 'de-DE', 'ssml' => false, 'kokoro' => false])
        ->and($basic(['language' => 'ur-PK'])['language'])->toBe('en-US')
        // A paid voice that doesn't speak the language falls back to the basic one.
        ->and($basic(['language' => 'fr-FR', 'phone_voice' => 'Polly.Joanna-Neural'])['voice'])->toBe('woman')
        ->and($basic(['phone_voice' => 'kokoro'])['kokoro'])->toBeFalse();
});

test('the phone line can play the self-hosted voice, from a signed link', function () {
    kokoroOn();
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    VoiceProfile::save($this->workspace->refresh(), ['phone_voice' => 'kokoro']);

    $xml = simplexml_load_string(voiceTwilio('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006'])->getContent());
    $url = (string) $xml->Gather->Play;

    expect($url)->toStartWith('https://docchat.test/twilio/voice/audio/')->and((string) $xml->Gather->Say)->toBe('');

    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->get($path.'?sig='.$query['sig'])->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    $this->get($path.'?sig=wrong')->assertForbidden();
});

test('the hold sound is a short, quiet WAV', function () {
    $response = $this->get('/twilio/voice/hold.wav')->assertOk()->assertHeader('Content-Type', 'audio/wav');
    [$format, $data] = Wav::parse($response->getContent());

    expect(strlen($data))->toBe(3 * 8000 * 2);
    $peak = max(array_map('abs', array_values(unpack('s*', $data))));
    expect($peak)->toBeLessThan(4000)->toBeGreaterThan(500);
});

test('without fillers or hold music, a slow phone answer waits quietly', function () {
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    VoiceProfile::save($this->workspace->refresh(), ['fillers' => false, 'hold_music' => false]);
    voiceTwilio('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006']);
    $call = Call::query()->withoutGlobalScopes()->sole();

    $xml = simplexml_load_string(voiceTwilio('twilio/voice/calls/'.$call->id.'/gather', ['CallSid' => 'CA1', 'SpeechResult' => 'When do you open?'])->getContent());

    expect((string) $xml->Say)->toBe('')->and((string) $xml->Play)->toBe('')->and(isset($xml->Pause))->toBeTrue();
});

test('a phone answer that never comes ends the wait politely', function () {
    config(['docchat.calls.phone_answer_wait_seconds' => 6]);
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    voiceTwilio('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006']);
    $call = Call::query()->withoutGlobalScopes()->sole();

    $xml = simplexml_load_string(voiceTwilio('twilio/voice/calls/'.$call->id.'/reply/abcdefghijklmnop?attempt=2', ['CallSid' => 'CA1'])->getContent());

    expect((string) $xml->Gather->Say)->toContain("I'm having trouble answering");
});

// Voicemail

function voicemailTestCall(string $phone = '+923001234567'): Call
{
    $contact = CallContact::query()->create([
        'workspace_id' => test()->workspace->id, 'name' => 'Sara Khan', 'phone' => $phone,
        'consent_at' => now(), 'consent_note' => 'asked',
    ]);

    return Call::query()->create([
        'workspace_id' => test()->workspace->id, 'contact_id' => $contact->id,
        'direction' => CallDirection::Outbound, 'transport' => CallTransport::Phone,
        'status' => CallStatus::Queued, 'purpose' => 'your card', 'to_number' => $phone,
    ]);
}

test('voicemail detection asks Twilio to wait for the beep and leaves a disclosed message', function () {
    VoiceProfile::save($this->workspace, ['voicemail' => 'leave_message']);
    $call = voicemailTestCall();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA777'], 201)]);

    (new PlaceTwilioCallJob($call->id))->handle(app(TwilioClient::class), app(CurrentWorkspace::class));
    Http::assertSent(fn (Request $request) => $request['MachineDetection'] === 'DetectMessageEnd');

    $response = voiceTwilio('twilio/voice/calls/'.$call->id.'/answer', ['CallSid' => 'CA777', 'AnsweredBy' => 'machine_end_beep'])->assertOk();

    expect($response->getContent())
        ->toContain('Hi, this is Ava, a virtual assistant calling for Harbor Library about your card. Please call us back')
        ->toContain('<Hangup/>')
        ->not->toContain('<Gather');
    $call->refresh();
    expect($call->status)->toBe(CallStatus::Completed)->and($call->end_reason)->toBe('voicemail_left')->and($call->answered_by)->toBe('machine_end_beep');
});

test('voicemail detection can just hang up, and a person still gets the conversation', function () {
    VoiceProfile::save($this->workspace, ['voicemail' => 'hang_up']);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

    $machine = voicemailTestCall();
    (new PlaceTwilioCallJob($machine->id))->handle(app(TwilioClient::class), app(CurrentWorkspace::class));
    Http::assertSent(fn (Request $request) => $request['MachineDetection'] === 'Enable');
    $content = voiceTwilio('twilio/voice/calls/'.$machine->id.'/answer', ['CallSid' => 'CA1', 'AnsweredBy' => 'machine_start'])->getContent();
    expect($content)->toContain('<Hangup/>')->not->toContain('<Say');
    expect($machine->refresh()->end_reason)->toBe('voicemail_hung_up');

    $person = voicemailTestCall(phone: '+923001234568');
    $person->forceFill(['provider_sid' => 'CA2'])->save();
    $content = voiceTwilio('twilio/voice/calls/'.$person->id.'/answer', ['CallSid' => 'CA2', 'AnsweredBy' => 'human'])->getContent();
    expect($content)->toContain('<Gather')->toContain('stop calling');
});

test('voicemail detection is off by default, so no Twilio credit is used for it', function () {
    $call = voicemailTestCall();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA9'], 201)]);

    (new PlaceTwilioCallJob($call->id))->handle(app(TwilioClient::class), app(CurrentWorkspace::class));

    Http::assertSent(fn (Request $request) => ! isset($request['MachineDetection']));
});

test('TwiML escapes what it says', function () {
    $xml = TwiML::for($this->workspace)->hangUp('Fish & <chips>')->getContent();

    expect($xml)->toContain('Fish &amp; &lt;chips&gt;');
});
