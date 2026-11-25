<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiKeyType;
use App\Enums\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AskRequest;
use App\Http\Requests\Api\CompleteDeviceAnswerRequest;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\Rag\AnswerService;
use App\Services\Rag\ApiAnswerer;
use App\Services\Rag\DevicePrompt;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

/**
 * In-browser AI: DocChat searches the documents, the asker's browser writes
 * the answer with its own on-device model, and sends it back to be saved
 * with citations. Costs the workspace no model tokens.
 */
class DeviceAskController extends Controller
{
    public const SETTING = 'device_answers';

    public function __construct(
        private CurrentWorkspace $current,
        private ApiAnswerer $answerer,
        private AnswerService $answers,
    ) {}

    public static function allowed(Workspace $workspace): bool
    {
        return (bool) config('docchat.device.enabled') && $workspace->setting(self::SETTING, false) === true;
    }

    /**
     * Ask, and answer on the device.
     *
     * Same input as POST /ask. Needs "Answer on visitors' devices" turned on
     * in the workspace's widget settings. When the answer needs no model (a
     * cached answer, nothing found), status is "answered" with the same data
     * as POST /ask. Otherwise status is "generate": run messages through an
     * on-device model (for example Chrome's LanguageModel API) and send the
     * text to POST /ask/device/complete with the token, within expires_in
     * seconds.
     *
     * @response array{data: array{status: 'answered'|'generate', conversation_id: int, token?: string, messages?: list<array{role: string, content: string}>, max_output_tokens?: int, expires_in?: int, answer?: string}}
     */
    public function prepare(AskRequest $request): JsonResponse
    {
        $workspace = $this->current->require();

        if (! self::allowed($workspace)) {
            return ApiError::response(403, 'device_answers_off', 'On-device answers are turned off for this workspace.');
        }

        $conversation = $this->answerer->conversation(
            $workspace,
            $request->channel(),
            $request->string('external_conversation_id')->toString() ?: null,
            $request->documentIds(),
        );

        $result = $this->answers->prepareForDevice($conversation, $request->string('question')->toString());

        if ($result instanceof DevicePrompt) {
            return new JsonResponse(['data' => ['status' => 'generate', 'conversation_id' => $conversation->id] + $result->toArray()]);
        }

        if ($result->error !== null) {
            return AskController::failed($result);
        }

        return new JsonResponse(['data' => ['status' => 'answered']
            + $this->answerer->payload($conversation, $result, $request->messageFormat())]);
    }

    /**
     * Save an answer written on the device.
     *
     * Send the token from POST /ask/device and the text the on-device model
     * wrote. Returns the same data as POST /ask, with citations mapped from
     * the [n] markers. The token works once.
     *
     * @response array{data: array{answer: string, citations: list<array{n: int, document_id: int, title: string, page: int, snippet: string}>, refused: bool, conversation_id: int, message_id: int}}
     */
    public function complete(CompleteDeviceAnswerRequest $request): JsonResponse
    {
        $workspace = $this->current->require();

        if (! self::allowed($workspace)) {
            return ApiError::response(403, 'device_answers_off', 'On-device answers are turned off for this workspace.');
        }

        $conversation = Conversation::query()->inWorkspace($workspace)->find($request->integer('conversation_id'));

        $publicKey = $this->current->apiKey()?->type === ApiKeyType::Public;

        if ($conversation === null || ($publicKey && $conversation->channel !== ConversationChannel::Widget)) {
            return ApiError::response(404, 'not_found', 'Conversation not found.');
        }

        $message = $this->answers->completeFromDevice(
            $conversation,
            $request->string('token')->toString(),
            (string) $request->input('answer', ''),
            $request->filled('error') ? 'The AI on this device failed: '.$request->string('error')->toString() : null,
        );

        if ($message === null) {
            return ApiError::response(410, 'token_expired', 'This answer token was used or has expired. Ask again.');
        }

        if ($message->error !== null) {
            return ApiError::response(422, 'device_failed', $message->error);
        }

        return new JsonResponse(['data' => $this->answerer->payload($conversation, $message, null)]);
    }
}
