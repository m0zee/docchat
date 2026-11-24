<?php

namespace App\Http\Controllers;

use App\Enums\ConversationChannel;
use App\Jobs\DiscordAnswerJob;
use App\Models\ChannelLink;
use App\Models\ChannelLinkCode;
use App\Models\Conversation;
use App\Services\Discord\DiscordSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discord's Interactions Endpoint URL. Discord POSTs slash commands here,
 * signed with Ed25519, and needs an answer within 3 seconds, so /ask is
 * deferred and answered by a queued job.
 *
 * A Discord server (guild) is linked to a workspace like a Telegram chat;
 * in direct messages the DM channel is linked instead.
 */
class DiscordInteractionController extends Controller
{
    private const PING = 1;

    private const COMMAND = 2;

    private const PONG = 1;

    private const MESSAGE = 4;

    private const DEFERRED_MESSAGE = 5;

    private const EPHEMERAL = 64;

    private const ADMINISTRATOR = 0x8;

    private const MANAGE_GUILD = 0x20;

    public function __invoke(Request $request): JsonResponse
    {
        $publicKey = (string) config('docchat.channels.discord.public_key');

        if ($publicKey === '' || ! DiscordSignature::verify(
            $request->getContent(),
            (string) $request->header('X-Signature-Ed25519'),
            (string) $request->header('X-Signature-Timestamp'),
            $publicKey,
        )) {
            return new JsonResponse(['error' => 'invalid request signature'], 401);
        }

        $interaction = $request->json()->all();

        return match ((int) ($interaction['type'] ?? 0)) {
            self::PING => new JsonResponse(['type' => self::PONG]),
            self::COMMAND => $this->command($interaction),
            default => self::reply('This interaction is not supported.'),
        };
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function command(array $interaction): JsonResponse
    {
        $name = (string) data_get($interaction, 'data.name');
        $guildId = data_get($interaction, 'guild_id');
        $channelId = (string) data_get($interaction, 'channel_id');
        // A server is linked as a whole; a DM is linked by its channel.
        $linkId = is_string($guildId) && $guildId !== '' ? $guildId : 'dm:'.$channelId;
        $link = ChannelLink::find(ConversationChannel::Discord, $linkId);

        return match ($name) {
            'link' => $this->link($interaction, $linkId),
            'unlink' => $this->unlink($interaction, $link),
            'new' => $this->newConversation($link, $channelId),
            'ask' => $this->ask($interaction, $link, $channelId),
            default => self::reply('Unknown command.'),
        };
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function link(array $interaction, string $linkId): JsonResponse
    {
        if (! self::canManage($interaction)) {
            return self::reply('Only members with **Manage Server** can link this server.');
        }

        $label = (string) (data_get($interaction, 'guild.name') ?? data_get($interaction, 'user.username') ?? $linkId);
        $link = ChannelLinkCode::redeem((string) self::option($interaction, 'code'), ConversationChannel::Discord, $linkId, $label);

        return $link === null
            ? self::reply('That code did not work. It may be used or older than 15 minutes. Create a new one in DocChat under Workspace > Chat apps.')
            : self::reply('Linked to **'.self::escape($link->workspace->name).'**. Anyone here can now use `/ask`.');
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function unlink(array $interaction, ?ChannelLink $link): JsonResponse
    {
        if (! self::canManage($interaction)) {
            return self::reply('Only members with **Manage Server** can unlink this server.');
        }

        $link?->delete();

        return self::reply($link === null ? 'This server was not linked.' : 'Unlinked. `/ask` stops working here until it is linked again.');
    }

    private function newConversation(?ChannelLink $link, string $channelId): JsonResponse
    {
        if ($link === null) {
            return self::notLinked();
        }

        Conversation::query()->inWorkspace($link->workspace)
            ->where('channel', ConversationChannel::Discord->value)
            ->where('external_id', 'discord:'.$channelId)
            ->update(['external_id' => null]);

        return self::reply('Started a new conversation in this channel.');
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function ask(array $interaction, ?ChannelLink $link, string $channelId): JsonResponse
    {
        if ($link === null) {
            return self::notLinked();
        }

        $question = trim((string) self::option($interaction, 'question'));

        if ($question === '') {
            return self::reply('Add a question, like `/ask question: How long can I keep a DVD?`');
        }

        DiscordAnswerJob::dispatch(
            (string) data_get($interaction, 'application_id'),
            (string) data_get($interaction, 'token'),
            $link->workspace_id,
            'discord:'.$channelId,
            mb_substr($question, 0, 2000),
        );

        // "Thinking...": the job edits this message with the answer.
        return new JsonResponse(['type' => self::DEFERRED_MESSAGE]);
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private static function canManage(array $interaction): bool
    {
        if (data_get($interaction, 'guild_id') === null) {
            return true;
        }

        $permissions = (int) data_get($interaction, 'member.permissions', '0');

        return ($permissions & (self::ADMINISTRATOR | self::MANAGE_GUILD)) !== 0;
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private static function option(array $interaction, string $name): mixed
    {
        foreach ((array) data_get($interaction, 'data.options', []) as $option) {
            if (is_array($option) && ($option['name'] ?? null) === $name) {
                return $option['value'] ?? null;
            }
        }

        return null;
    }

    private static function notLinked(): JsonResponse
    {
        return self::reply('This server is not linked to DocChat yet. A workspace owner creates a code in DocChat (Workspace > Chat apps), then someone with Manage Server runs `/link code: <code>` here.');
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\*_`~|>])/', '\\\\$1', $text);
    }

    /**
     * An immediate reply only the person who ran the command sees.
     */
    private static function reply(string $content): JsonResponse
    {
        return new JsonResponse(['type' => self::MESSAGE, 'data' => [
            'content' => $content,
            'flags' => self::EPHEMERAL,
            'allowed_mentions' => ['parse' => []],
        ]]);
    }
}
