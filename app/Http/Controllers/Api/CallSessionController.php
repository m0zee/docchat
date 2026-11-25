<?php

namespace App\Http\Controllers\Api;

use App\Enums\CallStatus;
use App\Exceptions\SpeechException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateCallToken;
use App\Models\Call;
use App\Services\Calls\CallAgent;
use App\Services\Calls\VoiceProfile;
use App\Services\Voice\KokoroTextToSpeech;
use App\Services\Voice\PocketTtsVoice;
use App\Services\Voice\Transcriber;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/**
 * A browser call in progress, authenticated by its call token.
 */
class CallSessionController extends Controller
{
    public function __construct(private CallAgent $agent) {}

    /**
     * Answer a call.
     *
     * Opens an outbound call link (or a test call) and returns the opening
     * line to read aloud.
     *
     * @response array{data: array{greeting: string, purpose: string|null, max_seconds: int, voice: array<string, mixed>}}
     */
    public function answer(Request $request): JsonResponse
    {
        $call = AuthenticateCallToken::call($request);
        $greeting = $this->agent->begin($call);
        CallAgent::remember($call, $greeting);

        return new JsonResponse(['data' => [
            'greeting' => $greeting,
            'purpose' => $call->purpose,
            'max_seconds' => Call::maxSeconds(),
            'voice' => self::clientVoice($call, VoiceProfile::for($call->workspace)),
        ]]);
    }

    /**
     * Say something on the call.
     *
     * Send what the caller said as recorded audio (multipart "audio": webm,
     * ogg, mp3, wav or m4a) or as "text". Nothing at all counts as silence.
     * The reply is the agent's answer to read aloud; when ended is true the
     * agent hung up after it. lang is the language to speak in (it follows
     * the caller when auto language detection is on), with its fillers.
     *
     * @response array{data: array{heard: string, reply: string, citations: list<array<string, mixed>>, refused: bool, ended: bool, error: string|null, lang: string, fillers: list<string>, engine: string}}
     */
    public function turn(Request $request, Transcriber $transcriber): JsonResponse
    {
        $call = AuthenticateCallToken::call($request);

        if ($call->status !== CallStatus::InProgress) {
            return ApiError::response(409, 'call_not_started', 'Answer the call first.');
        }

        $request->validate([
            'text' => ['nullable', 'string', 'max:1000'],
            'audio' => ['nullable', 'file', 'max:'.max(1, (int) config('docchat.voice.stt.max_upload_kb'))],
        ]);

        $heard = trim((string) $request->input('text', ''));
        $audio = $request->file('audio');
        $profile = VoiceProfile::for($call->workspace);
        $detected = null;

        if ($heard === '' && $audio instanceof UploadedFile) {
            // Auto detection lets Whisper find the language; otherwise tell it.
            $hint = $profile['auto_language'] ? null : VoiceProfile::LANGUAGES[$profile['language']]['whisper'];

            try {
                $transcript = $transcriber->upload($call->workspace, $audio, $hint);
                $heard = $transcript->text;
                $detected = VoiceProfile::languageCode($transcript->language);
            } catch (SpeechException $exception) {
                report($exception);
                $text = $this->agent->phrase($call, $exception->retryable ? 'speech_retry' : 'speech_down');
                CallAgent::remember($call, $text);

                return new JsonResponse(['data' => [
                    'heard' => '',
                    'reply' => $text,
                    'citations' => [],
                    'refused' => false,
                    'ended' => false,
                    'error' => $exception->retryable ? 'speech_busy' : 'speech_unavailable',
                    ...$this->voice($call, $profile),
                ]]);
            }
        } elseif ($heard !== '' && $profile['auto_language']) {
            $detected = self::guessScript($heard);
        }

        $reply = $this->agent->respond($call, self::clean($heard), $detected);
        CallAgent::remember($call, $reply->text);

        return new JsonResponse(['data' => [
            'heard' => self::clean($heard),
            'reply' => $reply->text,
            'citations' => array_map(fn (array $c): array => [
                'n' => (int) ($c['n'] ?? 0),
                'title' => (string) ($c['title'] ?? ''),
                'page' => (int) ($c['page'] ?? 0),
            ], $reply->citations),
            'refused' => $reply->refused,
            'ended' => $reply->end,
            'error' => null,
            ...$this->voice($call, $profile),
        ]]);
    }

    /**
     * Speak a line of this call with the self-hosted Kokoro voice.
     *
     * Only the call's own recent lines (or a filler) can be spoken, so the
     * call token can't be used as a free text-to-speech service.
     *
     * @response 200 string audio/mpeg
     */
    public function speech(Request $request, KokoroTextToSpeech $kokoro, PocketTtsVoice $pocket): Response|JsonResponse
    {
        $call = AuthenticateCallToken::call($request);
        $request->validate(['text' => ['required', 'string', 'max:700']]);
        $profile = VoiceProfile::for($call->workspace);
        $clone = $profile['web_engine'] === 'clone' ? VoiceProfile::voiceClone($call->workspace, $profile, $call->language) : null;
        $voice = VoiceProfile::kokoroVoice($profile, $call->language);
        $text = trim((string) $request->input('text'));

        if ($clone === null && (! KokoroTextToSpeech::configured() || $voice === null)) {
            return ApiError::response(404, 'voice_unavailable', 'The self-hosted voice is not set up for this language.');
        }

        if (! $this->agent->maySpeak($call, $text)) {
            return ApiError::response(422, 'not_this_call', 'Only this call\'s own lines can be spoken.');
        }

        try {
            if ($clone !== null) {
                return new Response($pocket->speak($clone, $text), 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, max-age=600']);
            }

            $audio = $kokoro->speakAs($text, (string) $voice, VoiceProfile::prosody($profile)['rate']);
        } catch (SpeechException $exception) {
            report($exception);

            return ApiError::response($exception->retryable ? 503 : 502, 'voice_failed', 'The voice could not be made. The browser voice will be used.');
        }

        return new Response($audio, 200, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=600']);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{lang: string, fillers: list<string>, engine: string}
     */
    private function voice(Call $call, array $profile): array
    {
        $client = self::clientVoice($call, $profile);

        return ['lang' => $client['lang'], 'fillers' => $client['fillers'], 'engine' => $client['engine']];
    }

    /**
     * The voice settings for the browser, with the clone when it is picked
     * and can speak the call's language.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function clientVoice(Call $call, array $profile): array
    {
        $clone = $profile['web_engine'] === 'clone' ? VoiceProfile::voiceClone($call->workspace, $profile, $call->language) : null;

        return VoiceProfile::client($profile, $call->language, $clone);
    }

    /**
     * A typed message's language, from its script (Devanagari, Arabic). Latin
     * text can't be told apart this way, so it keeps the call's language.
     */
    public static function guessScript(string $text): ?string
    {
        if (preg_match('/\p{Devanagari}/u', $text) === 1) {
            return 'hi';
        }

        if (preg_match('/[\x{0679}\x{0688}\x{0691}\x{06BA}\x{06BE}\x{06C1}\x{06D2}]/u', $text) === 1) {
            return 'ur';
        }

        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }

        return null;
    }

    /**
     * Hang up.
     *
     * @response array{data: array{ended: bool}}
     */
    public function end(Request $request): JsonResponse
    {
        $this->agent->hangUp(AuthenticateCallToken::call($request));

        return new JsonResponse(['data' => ['ended' => true]]);
    }

    /**
     * Whisper writes known filler for silent audio; treat it as nothing heard.
     */
    public static function clean(string $heard): string
    {
        $heard = trim($heard);
        $plain = strtolower(trim($heard, " .!?\t\n"));

        return in_array($plain, ['', 'you', 'thanks for watching', 'thank you for watching', 'please subscribe'], true) ? '' : $heard;
    }
}
