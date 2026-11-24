<?php

namespace App\Services\Webhooks;

use App\Enums\DocumentSource;
use App\Jobs\SendChannelWebhookJob;
use App\Jobs\SendWebhookJob;
use App\Models\Document;
use App\Models\Webhook;

class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(int $workspaceId, string $event, array $data): int
    {
        $webhooks = Webhook::query()->where('workspace_id', $workspaceId)->where('active', true)->get()
            ->filter(fn (Webhook $webhook): bool => $webhook->wants($event));

        foreach ($webhooks as $webhook) {
            SendWebhookJob::dispatch($webhook, $event, $data);
        }

        return $webhooks->count();
    }

    public function document(Document $document, string $event): void
    {
        $data = [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'status' => $document->status->value,
                'error' => $document->error,
                'source' => $document->source->value,
                'external_id' => $document->external_id,
                'page_count' => $document->page_count,
                'meta' => (object) ($document->meta ?? []),
            ],
        ];

        $this->dispatch($document->workspace_id, $event, $data);

        if (in_array($document->source, [DocumentSource::Telegram, DocumentSource::WhatsApp], true)
            && filled(config('docchat.channels.webhook_url')) && filled(config('docchat.channels.webhook_secret'))) {
            // Sent to a shared bot: tell the bot's workflow, which replies in the chat.
            SendChannelWebhookJob::dispatch($document->source->value, $event, $document->workspace_id, $data);
        }
    }
}
