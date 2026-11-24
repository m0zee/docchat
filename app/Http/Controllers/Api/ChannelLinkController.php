<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Models\ChannelLink;
use App\Models\ChannelLinkCode;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Links chats to workspaces, for bots shared by many workspaces.
 * Needs a service key (dc_svc_...) with the channels:link ability.
 */
class ChannelLinkController extends Controller
{
    /**
     * Link a chat with a one-time code.
     *
     * The owner creates the code in DocChat (Workspace > Chat apps) and sends
     * it to the bot, for example as "/start <code>" in Telegram. A code works
     * once, for 15 minutes. A chat linked before moves to the new workspace.
     *
     * @response array{data: array{linked: true, channel: string, external_id: string, workspace: array{id: int, name: string}}}
     */
    public function link(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            /** The chat id in the chat app. */
            'external_id' => ['required', 'string', 'max:255'],
            /** A name to show in DocChat, such as the chat title. */
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $link = ChannelLinkCode::redeem($data['code'], ConversationChannel::from($channel), $data['external_id'], $data['label'] ?? null);

        if ($link === null) {
            return ApiError::response(422, 'invalid_code', 'This code is not valid, was already used, or has expired. Create a new one in DocChat.');
        }

        return new JsonResponse(['data' => self::payload($link)]);
    }

    /**
     * Find the workspace a chat is linked to.
     *
     * Answers linked: false for a chat that is not linked, so a bot can
     * reply with how to link it.
     *
     * @response array{data: array{linked: bool, channel?: string, external_id?: string, workspace?: array{id: int, name: string}}}
     */
    public function resolve(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate(['external_id' => ['required', 'string', 'max:255']]);
        $link = ChannelLink::find(ConversationChannel::from($channel), $data['external_id']);

        return new JsonResponse(['data' => $link === null ? ['linked' => false] : self::payload($link)]);
    }

    /**
     * Unlink a chat.
     *
     * @response array{data: array{linked: false, was_linked: bool}}
     */
    public function unlink(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate(['external_id' => ['required', 'string', 'max:255']]);
        $link = ChannelLink::find(ConversationChannel::from($channel), $data['external_id']);
        $link?->delete();

        return new JsonResponse(['data' => ['linked' => false, 'was_linked' => $link !== null]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(ChannelLink $link): array
    {
        return [
            'linked' => true,
            'channel' => $link->channel->value,
            'external_id' => $link->external_id,
            'workspace' => ['id' => $link->workspace->id, 'name' => $link->workspace->name],
        ];
    }
}
