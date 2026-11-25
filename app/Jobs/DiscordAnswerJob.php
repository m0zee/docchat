<?php

namespace App\Jobs;

use App\Enums\ConversationChannel;
use App\Models\Workspace;
use App\Services\Discord\DiscordCommands;
use App\Services\Formatting\MessageFormat;
use App\Services\Rag\AnswerService;
use App\Services\Rag\ApiAnswerer;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Answers a deferred /ask: edits the "thinking" message with the first part
 * of the answer and sends the rest as follow-ups, each under Discord's
 * 2000 characters. The interaction token is valid for 15 minutes.
 */
class DiscordAnswerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [5];

    public function __construct(
        public string $applicationId,
        public string $token,
        public int $workspaceId,
        public string $conversationId,
        public string $question,
    ) {}

    public function handle(ApiAnswerer $answerer, CurrentWorkspace $current): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            $this->send(['Sorry, this server\'s DocChat workspace no longer exists.']);

            return;
        }

        $current->set($workspace);
        $conversation = $answerer->conversation($workspace, ConversationChannel::Discord, $this->conversationId, null);
        $message = $answerer->answer($conversation, $this->question);

        if ($message->error !== null) {
            $this->send([$message->error === AnswerService::BUSY
                ? 'The model is busy. Try again in a minute.'
                : 'Sorry, the answer could not be completed. Please try again.']);

            return;
        }

        $parts = $answerer->payload($conversation, $message, MessageFormat::Discord)['formatted'] ?? [];
        $this->send($parts === [] ? [AnswerService::NOT_FOUND] : $parts);
    }

    /**
     * @param  list<string>  $parts
     */
    private function send(array $parts): void
    {
        $base = DiscordCommands::apiBase().'/webhooks/'.$this->applicationId.'/'.$this->token;
        $quote = '> '.str_replace("\n", "\n> ", mb_substr($this->question, 0, 300))."\n";

        foreach ($parts as $index => $part) {
            $body = [
                // Keep the question visible above the answer, since a deferred reply hides it.
                'content' => $index === 0 && mb_strlen($quote.$part) <= 2000 ? $quote.$part : $part,
                'allowed_mentions' => ['parse' => []],
            ];

            $response = $index === 0
                ? Http::timeout(10)->patch($base.'/messages/@original', $body)
                : Http::timeout(10)->post($base, $body);

            if ($response->failed()) {
                throw new RuntimeException('Discord answered HTTP '.$response->status().'.');
            }
        }
    }
}
