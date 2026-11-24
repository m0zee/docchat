<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Signed delivery to the shared bots' workflow (docchat.channels.webhook_url).
 * The secret is read from config when sending, so it never sits in the queue.
 */
class SendChannelWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $channel,
        public string $event,
        public int $workspaceId,
        public array $data,
        public ?string $deliveryId = null,
    ) {
        $this->deliveryId ??= (string) Str::uuid();
    }

    public function handle(): void
    {
        // One workflow per chat app: "{channel}" in the URL becomes telegram, whatsapp...
        $url = str_replace('{channel}', $this->channel, (string) config('docchat.channels.webhook_url'));
        $secret = (string) config('docchat.channels.webhook_secret');

        if ($url === '' || $secret === '') {
            return;
        }

        $timestamp = time();
        $body = WebhookSigner::envelope((string) $this->deliveryId, $this->event, $timestamp, $this->workspaceId, $this->data);

        $response = Http::timeout(10)
            ->withHeaders(WebhookSigner::headers((string) $this->deliveryId, $this->event, $timestamp, $body, $secret))
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('Channel webhook answered HTTP '.$response->status().'.');
        }
    }
}
