<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public Webhook $webhook,
        public string $event,
        public array $data,
        public ?string $deliveryId = null,
    ) {
        $this->deliveryId ??= (string) Str::uuid();
    }

    public function handle(): void
    {
        $webhook = $this->webhook->refresh();

        if (! $webhook->active) {
            return;
        }

        $timestamp = time();
        $body = (string) json_encode([
            'id' => $this->deliveryId,
            'event' => $this->event,
            'timestamp' => $timestamp,
            'workspace_id' => $webhook->workspace_id,
            'data' => $this->data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'DocChat-Webhooks/1.0',
                    'X-DocChat-Event' => $this->event,
                    'X-DocChat-Delivery' => (string) $this->deliveryId,
                    'X-DocChat-Timestamp' => (string) $timestamp,
                    'X-DocChat-Signature' => WebhookSigner::sign($body, $webhook->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        } catch (Throwable $exception) {
            $webhook->update(['last_status' => null, 'last_error' => Str::limit($exception->getMessage(), 250)]);

            throw $exception;
        }

        $webhook->update([
            'last_status' => $response->status(),
            'last_error' => $response->successful() ? null : 'HTTP '.$response->status(),
            'last_delivered_at' => $response->successful() ? now() : $webhook->last_delivered_at,
        ]);

        if (! $response->successful()) {
            // Retried with backoff; the receiver may be down for a moment.
            throw new RuntimeException('Webhook answered HTTP '.$response->status().'.');
        }
    }
}
