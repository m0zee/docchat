<?php

namespace App\Http\Middleware;

use App\Enums\ConversationChannel;
use App\Models\ApiKey;
use App\Models\ChannelLink;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace keys already name their workspace. A service key (a shared bot)
 * names the chat instead, in "X-DocChat-Link: <channel>:<chat id>", and acts
 * in the workspace that chat is linked to.
 */
class ResolveApiWorkspace
{
    public const HEADER = 'X-DocChat-Link';

    public const ATTRIBUTE = 'docchat.channel_link';

    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        if (! $key instanceof ApiKey) {
            return ApiError::response(401, 'unauthenticated', 'Send your API key as "Authorization: Bearer dc_live_...".');
        }

        if ($key->workspace_id !== null) {
            return $next($request);
        }

        $header = trim((string) $request->header(self::HEADER));

        if (! preg_match('/^([a-z]+):(.{1,255})$/', $header, $matches)
            || ! ($channel = ConversationChannel::tryFrom($matches[1]))?->isLinkable()) {
            return ApiError::response(400, 'link_required', 'A service key must name the chat in "'.self::HEADER.': telegram:<chat id>".');
        }

        $link = ChannelLink::find($channel, $matches[2]);

        if ($link === null) {
            return ApiError::response(404, 'chat_not_linked', 'This chat is not linked to a workspace. Link it with a code from DocChat first.');
        }

        $request->attributes->set(self::ATTRIBUTE, $link);
        $this->current->set($link->workspace);

        return $next($request);
    }
}
