<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AskRequest;
use App\Services\Rag\AnswerService;
use App\Services\Rag\ApiAnswerer;
use App\Support\CurrentWorkspace;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AskStreamController extends Controller
{
    public function __construct(private CurrentWorkspace $current, private ApiAnswerer $answerer) {}

    /**
     * Ask a question and stream the answer as Server-Sent Events.
     *
     * Same input as POST /ask, as query parameters (GET) or a body (POST).
     * Events: "token" with {"text": "..."} for each piece of the answer,
     * then one "done" with the same data as POST /ask, or one "error" with
     * {"code": "...", "message": "..."}. Input errors are a normal JSON 422
     * before the stream starts.
     */
    public function __invoke(AskRequest $request): StreamedResponse
    {
        $workspace = $this->current->require();
        $apiKey = $this->current->apiKey();
        $conversation = $this->answerer->conversation(
            $workspace,
            $request->channel(),
            $request->string('external_conversation_id')->toString() ?: null,
            $request->documentIds(),
        );
        $question = $request->string('question')->toString();
        $format = $request->messageFormat();

        return response()->stream(function () use ($workspace, $apiKey, $conversation, $question, $format): void {
            // The stream can outlive the request's middleware; keep usage attributed.
            $this->current->set($workspace);
            $this->current->setApiKey($apiKey);

            try {
                $message = $this->answerer->answer($conversation, $question, function (string $delta): void {
                    self::send('token', ['text' => $delta]);
                });
            } catch (Throwable $exception) {
                report($exception);
                self::send('error', ['code' => 'answer_failed', 'message' => 'The answer could not be completed.']);

                return;
            }

            if ($message->error !== null) {
                self::send('error', [
                    'code' => $message->error === AnswerService::BUSY ? 'model_busy' : 'answer_failed',
                    'message' => $message->error,
                ]);

                return;
            }

            self::send('done', $this->answerer->payload($conversation, $message, $format));
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function send(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
