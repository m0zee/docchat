<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Contracts\SpeechToText;
use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Enums\ConversationChannel;
use App\Exceptions\SpeechException;
use App\Models\ApiKey;
use App\Models\ChannelLinkCode;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Voice\GroqSpeechToText;
use App\Services\Voice\GroqTextToSpeech;
use App\Services\Voice\Wav;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;
use Tests\Support\FakeSpeechToText;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider(['Open **9** to 5 [1].']));
    $this->stt = new FakeSpeechToText('When are you open?');
    app()->instance(SpeechToText::class, $this->stt);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($document)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);

    [, $this->service] = ApiKey::issue(null, 'bots', ApiAbility::forServiceKeys(), ApiKeyType::Service);
    [, $code] = ChannelLinkCode::issue($this->workspace, ConversationChannel::Telegram, $this->owner);
    ChannelLinkCode::redeem($code, ConversationChannel::Telegram, '1001', 'Moiz');
});

function voiceAsk(array $body = [], string $file = 'voice.oga')
{
    return test()->withToken(test()->service)
        ->withHeader('X-DocChat-Link', 'telegram:1001')
        ->post('/api/v1/voice/ask', $body + [
            'audio' => UploadedFile::fake()->createWithContent($file, str_repeat('o', 512)),
            'channel' => 'telegram',
            'external_conversation_id' => 'telegram:1001',
            'format' => 'telegram_html',
        ], ['Accept' => 'application/json']);
}

test('a voice note is transcribed and answered in the linked workspace', function () {
    $response = voiceAsk()
        ->assertOk()
        ->assertJsonPath('data.transcript', 'When are you open?')
        ->assertJsonPath('data.answer', 'Open **9** to 5 [1].')
        ->assertJsonPath('data.spoken', 'Open 9 to 5.')
        ->assertJsonPath('data.voice_replies', false);

    expect($response->json('data.formatted.0'))->toContain('<b>9</b>')
        ->and($this->stt->calls[0]['filename'])->toBe('speech.ogg');

    $conversation = Conversation::withoutGlobalScopes()->find($response->json('data.conversation_id'));
    expect($conversation->workspace_id)->toBe($this->workspace->id)
        ->and($conversation->channel)->toBe(ConversationChannel::Telegram)
        ->and($conversation->external_id)->toBe('telegram:1001');
});

test('a voice note with no words gets no_speech', function () {
    $this->stt->text = '  ';

    voiceAsk()->assertStatus(422)->assertJsonPath('error.code', 'no_speech');
});

test('busy speech recognition says so', function () {
    $this->stt->throw = SpeechException::busy();

    voiceAsk()->assertStatus(503)->assertJsonPath('error.code', 'speech_busy');
});

test('the audio file is required and the chat must be linked', function () {
    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:1001')
        ->postJson('/api/v1/voice/ask', ['channel' => 'telegram'])->assertStatus(422);
    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:999')
        ->post('/api/v1/voice/ask', ['audio' => UploadedFile::fake()->create('v.ogg', 1)], ['Accept' => 'application/json'])
        ->assertNotFound()->assertJsonPath('error.code', 'chat_not_linked');
});

test('voice replies are off unless the server turns them on', function () {
    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:1001')
        ->postJson('/api/v1/voice/speak', ['text' => 'Hello'])->assertNotFound()->assertJsonPath('error.code', 'voice_replies_off');
});

test('voice replies are spoken by Groq in pieces and turned into MP3', function () {
    config(['docchat.voice.replies' => true, 'docchat.voice.tts.api_key' => 'gsk_test']);
    app()->forgetInstance(SpeechToText::class);
    $wav = Wav::join([fakeWav('abcd')]);
    Http::fake(['api.groq.com/openai/v1/audio/speech' => Http::response($wav, 200, ['Content-Type' => 'audio/wav'])]);
    Process::fake(['*' => Process::result(output: 'ID3-mp3-bytes')]);

    $long = str_repeat('The library opens at nine in the morning. ', 6);
    $response = $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:1001')
        ->postJson('/api/v1/voice/speak', ['text' => $long.' [1]'])
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');

    expect(trim($response->getContent()))->toBe('ID3-mp3-bytes');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['model'] === 'canopylabs/orpheus-v1-english'
        && $request['response_format'] === 'wav'
        && mb_strlen($request['input']) <= 200
        && ! str_contains($request['input'], '[1]'));
    Process::assertRan(fn ($process) => in_array('mp3', $process->command, true));
});

test('Groq Whisper is called with the file and the model', function () {
    config(['docchat.voice.stt.api_key' => 'gsk_test', 'docchat.voice.stt.model' => 'whisper-large-v3-turbo']);
    Http::fake(['api.groq.com/openai/v1/audio/transcriptions' => Http::response(['text' => ' Hello there ', 'duration' => 1.5, 'language' => 'english'])]);

    $transcript = app(GroqSpeechToText::class)->transcribe('bytes', 'speech.webm', 'en');

    expect($transcript->text)->toBe('Hello there')->and($transcript->seconds)->toBe(1.5);
    Http::assertSent(fn (Request $request) => $request->isMultipart()
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'model' && $part['contents'] === 'whisper-large-v3-turbo')
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'language' && $part['contents'] === 'en')
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'file' && $part['filename'] === 'speech.webm'));
});

test('Groq Whisper rate limits become a busy error', function () {
    config(['docchat.voice.stt.api_key' => 'gsk_test']);
    Http::fake(['*' => Http::response(['error' => ['message' => 'slow down']], 429)]);

    expect(fn () => app(GroqSpeechToText::class)->transcribe('bytes', 'speech.webm'))
        ->toThrow(SpeechException::class, 'busy');
});

test('long text is split under 200 characters at sentence ends', function () {
    $pieces = GroqTextToSpeech::split(str_repeat('Short sentence here. ', 20).str_repeat('word ', 80));

    expect($pieces)->not->toBeEmpty();
    foreach ($pieces as $piece) {
        expect(mb_strlen($piece))->toBeLessThanOrEqual(200);
    }
    expect(implode(' ', $pieces))->toBe(trim(str_repeat('Short sentence here. ', 20).str_repeat('word ', 80)));
});

test('WAV pieces are joined into one file', function () {
    $joined = Wav::join([fakeWav('ab'), fakeWav('cdef')]);
    [$format, $data] = Wav::parse($joined);

    expect($data)->toBe('abcdef')->and(strlen($format))->toBe(16)
        ->and(unpack('V', substr($joined, 4, 4))[1])->toBe(strlen($joined) - 8);
});

function fakeWav(string $pcm): string
{
    $fmt = pack('vvVVvv', 1, 1, 24000, 48000, 2, 16);

    return 'RIFF'.pack('V', 4 + 8 + 16 + 8 + strlen($pcm)).'WAVE'.'fmt '.pack('V', 16).$fmt.'data'.pack('V', strlen($pcm)).$pcm;
}
