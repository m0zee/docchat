<?php

namespace App\Http\Controllers\Api;

use App\Contracts\TextToSpeech;
use App\Exceptions\SpeechException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VoiceAskRequest;
use App\Services\Rag\ApiAnswerer;
use App\Services\Voice\SpokenText;
use App\Services\Voice\Transcriber;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class VoiceController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    /**
     * Ask with a voice note.
     *
     * Same as POST /ask, but the question is a recording. The reply adds
     * "transcript" (what was heard) and "spoken" (the answer as plain text to
     * read aloud, for POST /voice/speak). Used by the Telegram and WhatsApp
     * bots for voice messages.
     *
     * @response array{data: array{transcript: string, spoken: string, voice_replies: bool, answer: string, citations: list<array{n: int, document_id: int, title: string, page: int, snippet: string}>, refused: bool, conversation_id: int, message_id: int, formatted?: list<string>}}
     */
    public function ask(VoiceAskRequest $request, Transcriber $transcriber, ApiAnswerer $answerer, TextToSpeech $tts): JsonResponse
    {
        $workspace = $this->current->require();
        $audio = $request->file('audio');

        if (! $audio instanceof UploadedFile) {
            return ApiError::response(422, 'validation_failed', 'Send the recording as "audio".');
        }

        try {
            $transcript = $transcriber->upload($workspace, $audio, $request->string('language')->toString() ?: null);
        } catch (SpeechException $exception) {
            report($exception);

            return $exception->retryable
                ? ApiError::response(503, 'speech_busy', $exception->getMessage(), ['retry_after' => 60], ['Retry-After' => '60'])
                : ApiError::response(502, 'speech_failed', $exception->getMessage());
        }

        $question = CallSessionController::clean($transcript->text);

        if ($question === '') {
            return ApiError::response(422, 'no_speech', 'No speech was heard in the recording.');
        }

        $conversation = $answerer->conversation(
            $workspace,
            $request->channel(),
            $request->string('external_conversation_id')->toString() ?: null,
            $request->documentIds(),
        );

        $message = $answerer->answer($conversation, mb_substr($question, 0, 2000));

        if ($message->error !== null) {
            return AskController::failed($message);
        }

        return new JsonResponse(['data' => [
            'transcript' => $question,
            'spoken' => SpokenText::from($message->content, (int) config('docchat.voice.tts.max_chars')),
            'voice_replies' => $tts->enabled(),
        ] + $answerer->payload($conversation, $message, $request->messageFormat())]);
    }

    /**
     * Read text aloud.
     *
     * Returns an MP3 of the text (up to 600 characters), for voice replies in
     * chat apps. Only when the server turned voice replies on
     * (DOCCHAT_VOICE_REPLIES); otherwise 404 voice_replies_off.
     */
    public function speak(Request $request, TextToSpeech $tts): Response
    {
        $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        if (! $tts->enabled()) {
            return ApiError::response(404, 'voice_replies_off', 'Voice replies are not turned on on this server.');
        }

        try {
            $mp3 = $tts->speak(SpokenText::from($request->string('text')->toString()));
        } catch (SpeechException $exception) {
            report($exception);

            return $exception->retryable
                ? ApiError::response(503, 'speech_busy', $exception->getMessage(), ['retry_after' => 60], ['Retry-After' => '60'])
                : ApiError::response(502, 'speech_failed', $exception->getMessage());
        }

        return new Response($mp3, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="answer.mp3"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
