<?php

namespace App\Http\Controllers\Api;

use App\Enums\CallStatus;
use App\Exceptions\SpeechException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateCallToken;
use App\Models\Call;
use App\Services\Calls\CallAgent;
use App\Services\Voice\Transcriber;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * @response array{data: array{greeting: string, purpose: string|null, max_seconds: int}}
     */
    public function answer(Request $request): JsonResponse
    {
        $call = AuthenticateCallToken::call($request);
        $greeting = $this->agent->begin($call);

        return new JsonResponse(['data' => [
            'greeting' => $greeting,
            'purpose' => $call->purpose,
            'max_seconds' => Call::maxSeconds(),
        ]]);
    }

    /**
     * Say something on the call.
     *
     * Send what the caller said as recorded audio (multipart "audio": webm,
     * ogg, mp3, wav or m4a) or as "text". Nothing at all counts as silence.
     * The reply is the agent's answer to read aloud; when ended is true the
     * agent hung up after it.
     *
     * @response array{data: array{heard: string, reply: string, citations: list<array<string, mixed>>, refused: bool, ended: bool, error: string|null}}
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

        if ($heard === '' && $audio instanceof UploadedFile) {
            try {
                $heard = $transcriber->upload($call->workspace, $audio)->text;
            } catch (SpeechException $exception) {
                report($exception);

                return new JsonResponse(['data' => [
                    'heard' => '',
                    'reply' => $exception->retryable
                        ? 'Sorry, I could not hear that properly. Please say it again, or type it.'
                        : 'Sorry, voice is not working right now. Please type your question.',
                    'citations' => [],
                    'refused' => false,
                    'ended' => false,
                    'error' => $exception->retryable ? 'speech_busy' : 'speech_unavailable',
                ]]);
            }
        }

        $reply = $this->agent->respond($call, self::clean($heard));

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
        ]]);
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
