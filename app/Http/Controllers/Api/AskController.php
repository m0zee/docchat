<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AskRequest;
use App\Models\Message;
use App\Services\Rag\AnswerService;
use App\Services\Rag\ApiAnswerer;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class AskController extends Controller
{
    public function __construct(private CurrentWorkspace $current, private ApiAnswerer $answerer) {}

    /**
     * Ask a question about the workspace's documents.
     *
     * Answers come only from the documents, with numbered citations. When
     * nothing relevant is found, the answer is "I couldn't find that in your
     * documents." and refused is true. Send the same external_conversation_id
     * to continue a conversation. With format, "formatted" holds message
     * parts ready to send to Telegram, WhatsApp or Discord.
     */
    public function __invoke(AskRequest $request): JsonResponse
    {
        $conversation = $this->answerer->conversation(
            $this->current->require(),
            $request->channel(),
            $request->string('external_conversation_id')->toString() ?: null,
            $request->documentIds(),
        );

        $message = $this->answerer->answer($conversation, $request->string('question')->toString());

        if ($message->error !== null) {
            return self::failed($message);
        }

        return new JsonResponse(['data' => $this->answerer->payload($conversation, $message, $request->messageFormat())]);
    }

    public static function failed(Message $message): JsonResponse
    {
        return $message->error === AnswerService::BUSY
            ? ApiError::response(503, 'model_busy', $message->error, ['retry_after' => 60], ['Retry-After' => '60'])
            : ApiError::response(502, 'answer_failed', (string) $message->error);
    }
}
