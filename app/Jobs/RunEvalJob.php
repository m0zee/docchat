<?php

namespace App\Jobs;

use App\Enums\EvalRunStatus;
use App\Evals\EvalRunner;
use App\Models\EvalRun;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one case of an eval run, then queues itself for the next case after
 * the run's delay. Short jobs keep the worker free for uploads and stay far
 * below the queue timeout, however many cases there are.
 */
class RunEvalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [30];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public EvalRun $run) {}

    public function handle(EvalRunner $runner, WebhookDispatcher $webhooks): void
    {
        $run = $this->run->refresh();

        if ($run->status->finished()) {
            return;
        }

        if ($run->status === EvalRunStatus::Queued) {
            $run->update(['status' => EvalRunStatus::Running, 'started_at' => now()]);
        }

        $cases = $run->evalCases();
        $index = count($run->results);

        if ($index < count($cases)) {
            $report = $runner->run($run->workspace, [$cases[$index]], $run->document_ids, 0);
            $result = $report['cases'][0];
            $run->update(['results' => [...$run->results, $result]]);

            if ($index + 1 < count($cases)) {
                // Only wait when this case used the chat model.
                $delay = $result['retrieved'] === [] ? 0 : (int) round($run->delay_seconds * 1000);
                self::dispatch($run)->delay(now()->addMilliseconds($delay));

                return;
            }
        }

        $run->update([
            'status' => EvalRunStatus::Completed,
            'summary' => [...EvalRunner::summarize($run->results), 'config' => EvalRunner::config($run->delay_seconds)],
            'finished_at' => now(),
        ]);

        self::notify($run, $webhooks);
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->run->refresh();

        if ($run->status->finished()) {
            return;
        }

        $run->update([
            'status' => EvalRunStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'The eval run failed.', 250),
            'summary' => $run->results === [] ? null : EvalRunner::summarize($run->results),
            'finished_at' => now(),
        ]);

        self::notify($run, app(WebhookDispatcher::class));
    }

    private static function notify(EvalRun $run, WebhookDispatcher $webhooks): void
    {
        if ($run->callback_url !== null) {
            SendEvalCallbackJob::dispatch($run);
        }

        $webhooks->dispatch($run->workspace_id, 'eval.completed', ['eval_run' => $run->payload(withCases: false)]);
    }
}
