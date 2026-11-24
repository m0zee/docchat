<?php

namespace App\Jobs;

use App\Models\EvalRun;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Posts a finished eval run to the callback URL given when it started,
 * signed like webhooks with the run's own callback secret.
 */
class SendEvalCallbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public EvalRun $run, public ?string $deliveryId = null)
    {
        $this->deliveryId ??= (string) Str::uuid();
    }

    public function handle(): void
    {
        $run = $this->run->refresh();

        if ($run->callback_url === null || $run->callback_secret === null) {
            return;
        }

        $timestamp = time();
        $body = WebhookSigner::envelope((string) $this->deliveryId, 'eval.completed', $timestamp, $run->workspace_id, [
            'eval_run' => $run->payload(),
        ]);

        $response = Http::timeout(10)
            ->withHeaders(WebhookSigner::headers((string) $this->deliveryId, 'eval.completed', $timestamp, $body, $run->callback_secret))
            ->withBody($body, 'application/json')
            ->post($run->callback_url);

        if (! $response->successful()) {
            throw new RuntimeException('Eval callback answered HTTP '.$response->status().'.');
        }
    }
}
