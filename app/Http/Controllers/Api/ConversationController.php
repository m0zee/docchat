<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    /**
     * End a conversation.
     *
     * The next question with the same external_conversation_id starts a new
     * conversation with no history, like a bot's /new command. The old one
     * stays in DocChat's history.
     *
     * @response array{data: array{ended: bool}}
     */
    public function end(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', Rule::enum(ConversationChannel::class)->except([ConversationChannel::Web, ConversationChannel::Eval])],
            'external_conversation_id' => ['required', 'string', 'max:255'],
        ]);

        $ended = Conversation::query()->inWorkspace($this->current->require())
            ->where('channel', $data['channel'] ?? ConversationChannel::Api->value)
            ->where('external_id', trim($data['external_conversation_id']))
            ->update(['external_id' => null]);

        return new JsonResponse(['data' => ['ended' => $ended > 0]]);
    }
}
