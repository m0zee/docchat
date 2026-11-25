<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Contracts\SpeechToText;
use App\Enums\VoiceCloneStatus;
use App\Enums\WorkspaceRole;
use App\Mail\VoiceConsentMail;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use App\Models\VoiceClone;
use App\Services\Calls\CallSettings;
use App\Services\Calls\VoiceProfile;
use App\Services\Twilio\TwilioSignature;
use App\Services\Voice\PocketTtsVoice;
use App\Services\Voice\VoiceConsent;
use App\Services\Voice\Wav;
use App\Services\Widget\WidgetSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        'docchat.calls.twilio.account_sid' => 'AC123',
        'docchat.calls.twilio.auth_token' => 'secret-token',
        'docchat.calls.twilio.from_number' => '+15005550006',
        'docchat.voice.kokoro.url' => null,
        'docchat.voice.clone.url' => null,
        'docchat.voice.clone.language' => 'en',
    ]);
    Storage::fake('local');
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider(['We open at **9** [1].']));
    $this->stt = new FakeSpeechToText('');
    app()->instance(SpeechToText::class, $this->stt);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $this->workspace->update(['name' => 'Harbor Library']);
    $document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($document)->create(['page_number' => 2, 'embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
});

/**
 * A clone waiting for consent, with its plain link token.
 *
 * @return array{VoiceClone, string}
 */
function waitingClone(array $attributes = []): array
{
    $token = VoiceClone::newToken();
    $language = $attributes['language'] ?? 'en';
    $words = $attributes['code_words'] ?? 'silver rocket';

    $clone = VoiceClone::query()->create($attributes + [
        'workspace_id' => test()->workspace->id,
        'created_by_user_id' => test()->owner->id,
        'name' => 'Front desk',
        'speaker_name' => 'Sara Khan',
        'language' => $language,
        'status' => VoiceCloneStatus::Waiting,
        'token_hash' => VoiceClone::hash($token),
        'code_words' => $words,
        'statement' => VoiceConsent::statement($language, 'Sara Khan', 'Harbor Library', $words),
        'expires_at' => now()->addDays(7),
    ]);

    return [$clone, $token];
}

function readyClone(array $attributes = []): VoiceClone
{
    [$clone] = waitingClone($attributes);
    $path = $clone->samplePath();
    Storage::disk('local')->put($path, consentWav());
    $clone->forceFill(['status' => VoiceCloneStatus::Ready, 'sample_path' => $path, 'sample_seconds' => 12, 'consented_at' => now()])->save();

    return $clone;
}

function consentWav(float $seconds = 12.0, int $rate = 8000): string
{
    $samples = [];

    for ($i = 0, $n = (int) ($seconds * $rate); $i < $n; $i++) {
        $samples[] = 0.2 * sin($i / 8);
    }

    return Wav::fromSamples($samples, $rate);
}

function sendRecording(string $token, string $wav, bool $confirm = true)
{
    return test()->post('/voice-consent/'.$token.'/recording', [
        'audio' => UploadedFile::fake()->createWithContent('consent.wav', $wav),
        'confirm' => $confirm ? '1' : '0',
    ], ['Accept' => 'application/json']);
}

/** A WAV as Pocket TTS streams it: placeholder sizes in the header. */
function streamedWav(): string
{
    $wav = Wav::fromSamples([0.1, 0.2, 0.3, 0.2], 24000);

    return 'RIFF'.pack('V', 0xFFFFFFFF).substr($wav, 8, 32).pack('V', 0xFFFFFFFF).substr($wav, 44);
}

function cloneServerOn(): void
{
    config(['docchat.voice.clone.url' => 'http://voice-clone:8000']);
    Http::fake(['voice-clone:8000/*' => Http::response(streamedWav(), 200, ['Content-Type' => 'audio/wav'])]);
}

function cloneTwilio(string $path, array $params)
{
    $url = 'https://docchat.test/'.$path;

    return test()->call('POST', '/'.$path, $params, [], [], [
        'HTTP_X_TWILIO_SIGNATURE' => TwilioSignature::sign($url, $params, 'secret-token'),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ]);
}

// Consent statement

test('the consent statement names the speaker, the workspace and two random code words', function () {
    $words = VoiceConsent::codeWords('en');
    [$first, $second] = explode(' ', $words);

    expect($first)->not->toBe($second)
        ->and(VoiceConsent::statement('en', 'Sara Khan', 'Harbor Library', $words))
        ->toBe('I, Sara Khan, agree that Harbor Library may create an AI copy of my voice for its virtual assistant. My code words are '.$words.'.')
        ->and(VoiceConsent::statement('de', 'Sara', 'Harbor', 'stern wald'))->toContain('einverstanden')
        ->and(VoiceConsent::passage('fr'))->toContain('Merci')
        // Unknown languages use English.
        ->and(VoiceConsent::language('xx'))->toBe('en');
});

test('the transcript must have the code words, the agree word and the name', function () {
    [$clone] = waitingClone();

    expect(VoiceConsent::problem($clone, 'I, Sara Khan, agree that Harbor Library may create an AI copy of my voice. My code words are silver rocket.'))->toBeNull()
        // Speech recognition spells a long word or a name a little off.
        ->and(VoiceConsent::problem($clone, 'I Sarah Kahn agreed. Code words: silvery rockets'))->toBeNull()
        ->and(VoiceConsent::problem($clone, 'I, Sara, agree. My code words are silver.'))->toContain('rocket')
        ->and(VoiceConsent::problem($clone, 'I, Sara, refuse. Silver rocket.'))->toContain('agree')
        ->and(VoiceConsent::problem($clone, 'I, Maria, agree. Silver rocket.'))->toContain('name')
        ->and(VoiceConsent::problem($clone, ''))->toContain('could not hear');

    [$spanish] = waitingClone(['language' => 'es', 'code_words' => 'jardín limón', 'token_hash' => 'x']);
    expect(VoiceConsent::problem($spanish, 'Yo, Sara Khan, acepto. Mis palabras clave son jardin, limon.'))->toBeNull();
});

// Calls page

test('owners ask for consent on the calls page; the link is shown once and can be emailed', function () {
    Mail::fake();
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.calls')
        ->set('cloneName', 'Front desk')
        ->set('cloneSpeaker', 'Sara Khan')
        ->set('cloneEmail', 'sara@example.com')
        ->call('createClone')
        ->assertHasNoErrors()
        ->assertSet('cloneLink', fn (string $link) => str_starts_with($link, 'https://docchat.test/voice-consent/dc_vc_'))
        ->assertSee('Waiting for consent');

    $clone = VoiceClone::query()->sole();
    expect($clone->status)->toBe(VoiceCloneStatus::Waiting)
        ->and($clone->statement)->toContain('I, Sara Khan, agree that Harbor Library')
        ->and($clone->statement)->toContain($clone->code_words)
        ->and($clone->expires_at?->isFuture())->toBeTrue();

    Mail::assertQueued(VoiceConsentMail::class, fn (VoiceConsentMail $mail) => $mail->hasTo('sara@example.com') && str_contains($mail->url, '/voice-consent/'));
});

test('a workspace has at most three voice clones, and members cannot make them', function () {
    $this->actingAs($this->owner);
    waitingClone(['token_hash' => 'a']);
    waitingClone(['token_hash' => 'b']);
    readyClone(['token_hash' => 'c']);

    Livewire::test('pages::workspace.calls')
        ->set('cloneName', 'Fourth')
        ->set('cloneSpeaker', 'Ali')
        ->call('createClone')
        ->assertHasErrors('cloneName');

    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->actingAs($member)->get('/workspace/calls')->assertForbidden();
});

test('a new link replaces the old one, with new code words', function () {
    $this->actingAs($this->owner);
    [$clone, $token] = waitingClone();

    Livewire::test('pages::workspace.calls')->call('renewCloneLink', $clone->id)->assertSet('cloneLink', fn ($link) => $link !== null);

    expect(VoiceClone::findByToken($token))->toBeNull();
    $this->get('/voice-consent/'.$token)->assertOk()->assertSee('This link is not valid');
});

// Consent page

test('the consent page shows the statement to read and records in the browser, with no file picker', function () {
    [, $token] = waitingClone();

    $this->get('/voice-consent/'.$token)
        ->assertOk()
        ->assertSee('Lend your voice to Harbor Library')
        ->assertSee('I, Sara Khan, agree that Harbor Library may create an AI copy of my voice', false)
        ->assertSee('silver rocket')
        ->assertSee('Start recording')
        ->assertDontSee('type="file"', false);

    $this->get('/voice-consent/dc_vc_nope')->assertOk()->assertSee('This link is not valid');
});

test('a recording that says the statement makes the voice ready', function () {
    [$clone, $token] = waitingClone();
    $this->stt->text = 'I, Sara Khan, agree that Harbor Library may create an AI copy of my voice for its virtual assistant. My code words are silver rocket. Thanks for calling.';

    sendRecording($token, consentWav())->assertOk()->assertJsonPath('data.status', 'ready');

    $clone->refresh();
    expect($clone->status)->toBe(VoiceCloneStatus::Ready)
        ->and($clone->isReady())->toBeTrue()
        ->and((float) $clone->sample_seconds)->toBe(12.0)
        ->and($clone->consented_at)->not->toBeNull()
        ->and($clone->consent_ip)->toBe('127.0.0.1')
        ->and($clone->transcript)->toContain('silver rocket')
        ->and($this->stt->calls[0]['language'])->toBe('en');
    Storage::disk('local')->assertExists($clone->sample_path);

    // Already agreed: the page offers to withdraw, not to record again.
    $this->get('/voice-consent/'.$token)->assertSee('Your voice is ready')->assertSee('Withdraw my consent');
    sendRecording($token, consentWav())->assertStatus(410);
});

test('a recording without the statement is refused, and five misses end the link', function () {
    [$clone, $token] = waitingClone();
    $this->stt->text = 'Hello, this is just me talking about the weather.';

    sendRecording($token, consentWav())->assertUnprocessable()->assertJsonPath('error.tries_left', 4);
    expect($clone->refresh()->status)->toBe(VoiceCloneStatus::Waiting)->and($clone->attempts)->toBe(1);

    foreach (range(1, 4) as $try) {
        sendRecording($token, consentWav())->assertUnprocessable();
    }

    expect($clone->refresh()->status)->toBe(VoiceCloneStatus::Failed)->and($clone->sample_path)->toBeNull();
    sendRecording($token, consentWav())->assertStatus(410);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('only a short WAV recorded with the box ticked is accepted', function () {
    [$clone, $token] = waitingClone();
    $this->stt->text = 'I, Sara Khan, agree. My code words are silver rocket.';

    sendRecording($token, consentWav(), confirm: false)->assertUnprocessable()->assertJsonValidationErrors('confirm');
    sendRecording($token, 'ID3 not a wav file at all')->assertUnprocessable()->assertJsonPath('error.message', fn ($m) => str_contains($m, 'could not be read'));
    sendRecording($token, consentWav(3))->assertUnprocessable()->assertJsonPath('error.message', fn ($m) => str_contains($m, 'between 8 and 45 seconds'));
    sendRecording($token, consentWav(60))->assertUnprocessable();

    // None of these used a try or reached speech recognition.
    expect($clone->refresh()->attempts)->toBe(0)->and($this->stt->calls)->toBe([]);
});

test('an expired link cannot record, but can still be used to say no', function () {
    [$clone, $token] = waitingClone(['expires_at' => now()->subDay()]);

    $this->get('/voice-consent/'.$token)->assertSee('This link has ended');
    sendRecording($token, consentWav())->assertStatus(410);

    $this->post('/voice-consent/'.$token.'/withdraw')->assertRedirect('/voice-consent/'.$token);
    expect($clone->refresh()->status)->toBe(VoiceCloneStatus::Withdrawn);
});

test('withdrawing deletes the recording and every clip, and calls stop using the voice', function () {
    cloneServerOn();
    $clone = readyClone();
    $token = VoiceClone::newToken();
    $clone->forceFill(['token_hash' => VoiceClone::hash($token)])->save();
    $sample = $clone->sample_path;
    $clip = app(PocketTtsVoice::class)->clip($clone, 'Hello there.');
    VoiceProfile::save($this->workspace, ['web_engine' => 'clone', 'clone_id' => $clone->id]);

    expect(VoiceProfile::voiceClone($this->workspace->refresh(), VoiceProfile::for($this->workspace)))->not->toBeNull();

    $this->post('/voice-consent/'.$token.'/withdraw');
    $this->get('/voice-consent/'.$token)->assertSee('Consent withdrawn');

    $clone->refresh();
    expect($clone->status)->toBe(VoiceCloneStatus::Withdrawn)
        ->and($clone->sample_path)->toBeNull()
        ->and($clone->withdrawn_at)->not->toBeNull()
        ->and(VoiceProfile::voiceClone($this->workspace, VoiceProfile::for($this->workspace)))->toBeNull()
        ->and(VoiceProfile::client(VoiceProfile::for($this->workspace))['engine'])->toBe('browser');
    Storage::disk('local')->assertMissing($sample);
    Storage::disk('local')->assertMissing(PocketTtsVoice::path($clip));
});

// Speaking with the clone

test('Pocket TTS gets the consent recording as the voice, and clips are cached with real WAV sizes', function () {
    cloneServerOn();
    $clone = readyClone();

    $audio = app(PocketTtsVoice::class)->speak($clone, 'We open at nine.');
    app(PocketTtsVoice::class)->speak($clone, 'We open at nine.');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $parts = collect($request->data())->keyBy('name');

        return $request->url() === 'http://voice-clone:8000/tts'
            && $request->isMultipart()
            && $parts['text']['contents'] === 'We open at nine.'
            && str_starts_with((string) $parts['voice_wav']['contents'], 'RIFF');
    });

    [, $data] = Wav::parse($audio);
    expect(unpack('V', substr($audio, 4, 4))[1])->toBe(strlen($audio) - 8)
        ->and(unpack('V', substr($audio, 40, 4))[1])->toBe(strlen($data));
});

test('a browser call can speak in the clone, only in the clone server\'s language', function () {
    cloneServerOn();
    $clone = readyClone();
    VoiceProfile::save($this->workspace, ['web_engine' => 'clone', 'clone_id' => $clone->id]);
    $key = WidgetSettings::rotateKey($this->workspace, ['https://shop.example.com']);

    $data = $this->withToken($key)->withHeader('Origin', 'https://shop.example.com')
        ->postJson('/api/v1/calls', ['external_conversation_id' => 'v'.str_repeat('c4', 18)])
        ->assertCreated()->json('data');
    $this->flushHeaders();

    expect($data['voice']['engine'])->toBe('clone');

    $this->withToken($data['token'])->postJson('/api/v1/call/speech', ['text' => 'Free advert'])->assertUnprocessable();
    $this->withToken($data['token'])->postJson('/api/v1/call/speech', ['text' => $data['greeting']])
        ->assertOk()->assertHeader('Content-Type', 'audio/wav');

    // A French call can't use an English clone server: the browser voice is used.
    VoiceProfile::save($this->workspace->refresh(), ['language' => 'fr-FR']);
    expect(VoiceProfile::client(VoiceProfile::for($this->workspace), null, VoiceProfile::voiceClone($this->workspace, VoiceProfile::for($this->workspace)))['engine'])->toBe('browser');
});

test('the phone line plays the clone from a signed link', function () {
    cloneServerOn();
    $clone = readyClone();
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    VoiceProfile::save($this->workspace->refresh(), ['phone_voice' => 'clone', 'clone_id' => $clone->id]);

    $xml = simplexml_load_string(cloneTwilio('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006'])->getContent());
    $url = (string) $xml->Gather->Play;

    expect($url)->toStartWith('https://docchat.test/twilio/voice/audio/c'.$clone->id.'_');

    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->get($path.'?sig='.$query['sig'])->assertOk()->assertHeader('Content-Type', 'audio/wav');
    $this->get($path.'?sig=wrong')->assertForbidden();
});

test('without the clone server the phone line falls back to the normal voice', function () {
    $clone = readyClone();
    CallSettings::save($this->workspace, ['phone_number' => '+15005550006']);
    VoiceProfile::save($this->workspace->refresh(), ['phone_voice' => 'clone', 'clone_id' => $clone->id]);

    $xml = simplexml_load_string(cloneTwilio('twilio/voice/inbound', ['CallSid' => 'CA1', 'To' => '+15005550006'])->getContent());

    expect((string) $xml->Gather->Say)->toContain('virtual assistant')->and((string) $xml->Gather->Play)->toBe('');
});

test('owners use, preview and delete a clone on the calls page', function () {
    cloneServerOn();
    $this->actingAs($this->owner);
    $clone = readyClone();

    $page = Livewire::test('pages::workspace.calls')
        ->assertSee('Front desk')
        ->call('useClone', $clone->id)
        ->assertSet('voice.web_engine', 'clone')
        ->assertSet('voice.phone_voice', 'clone')
        ->assertSet('voice.clone_id', (string) $clone->id);

    expect($page->instance()->previewClone($clone->id))->toStartWith('data:audio/wav;base64,');

    // The clone engine needs a ready clone.
    $page->set('voice.clone_id', '')->call('saveVoice')->assertHasErrors('voice.clone_id');

    $sample = $clone->sample_path;
    $page->call('deleteClone', $clone->id)->assertSet('voice.web_engine', 'browser')->assertSet('voice.phone_voice', 'basic');

    expect(VoiceClone::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($sample);
});

test('the clone server is optional: the docker service and settings are documented', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));
    $dockerfile = file_get_contents(base_path('docker/voice-clone/Dockerfile'));

    expect($compose)->toContain('voice-clone:')->toContain('profiles: ["clone"]')
        ->and($dockerfile)->toContain('pocket-tts==4.0.1')
        ->and(file_get_contents(base_path('.env.example')))->toContain('DOCCHAT_CLONE_URL=');
});
