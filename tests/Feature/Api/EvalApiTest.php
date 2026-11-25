<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ApiAbility;
use App\Enums\EvalRunStatus;
use App\Evals\EvalRunner;
use App\Jobs\RunEvalJob;
use App\Jobs\SendEvalCallbackJob;
use App\Jobs\SendWebhookJob;
use App\Models\ApiKey;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\EvalRun;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    Queue::fake();
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false, 'docchat.evals.delay_seconds' => 8]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider(['Refunds take 14 days [1].']));

    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'policy']);
    Chunk::factory()->for($this->document)->create(['page_number' => 2, 'content' => 'Refunds take 14 days.', 'embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    [, $this->key] = ApiKey::issue($this->workspace, 'n8n', [ApiAbility::EvalsRun]);

    $this->cases = [
        ['question' => 'How long do refunds take?', 'expected_keywords' => ['14 days'], 'expected_document' => 'policy', 'expected_page' => 2],
        ['question' => 'Where is the moon base?', 'should_refuse' => true],
    ];
});

function runEvalJobs(EvalRun $run): void
{
    for ($i = 0; $i < 10 && ! $run->refresh()->status->finished(); $i++) {
        (new RunEvalJob($run))->handle(app(EvalRunner::class), app(WebhookDispatcher::class));
    }
}

test('starting a run queues it and returns a callback secret', function () {
    $response = $this->withToken($this->key)->postJson('/api/v1/evals/run', [
        'cases' => $this->cases,
        'callback_url' => 'http://n8n:5678/webhook-waiting/42',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.cases', 2)
        ->assertJsonPath('data.completed_cases', 0);

    expect($response->json('data.callback_secret'))->toStartWith('whsec_');

    $run = EvalRun::withoutGlobalScopes()->sole();
    expect($run->workspace_id)->toBe($this->workspace->id)
        ->and($run->callback_secret)->toBe($response->json('data.callback_secret'))
        ->and($run->delay_seconds)->toBe(8.0);

    Queue::assertPushed(RunEvalJob::class, fn (RunEvalJob $job): bool => $job->run->is($run));
});

test('without cases the sample eval set is used and no secret is returned', function () {
    $this->withToken($this->key)->postJson('/api/v1/evals/run')
        ->assertStatus(202)
        ->assertJsonMissingPath('data.callback_secret');

    expect(EvalRun::withoutGlobalScopes()->sole()->cases)->not->toBeEmpty();
});

test('bad input is rejected', function (array $body, string $field) {
    $this->withToken($this->key)->postJson('/api/v1/evals/run', $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'unknown eval set' => [['eval_set' => 'nope'], 'eval_set'],
    'path in eval set' => [['eval_set' => '../.env'], 'eval_set'],
    'invalid case' => [['cases' => [['question' => '']]], 'cases'],
    'too many cases' => [['cases' => array_fill(0, 51, ['question' => 'q', 'should_refuse' => true])], 'cases'],
    'bad callback' => [['callback_url' => 'ftp://example.com'], 'callback_url'],
    'foreign documents' => [['document_ids' => [999999]], 'document_ids'],
]);

test('a workspace with no ready documents cannot run evals', function () {
    $this->document->delete();

    $this->withToken($this->key)->postJson('/api/v1/evals/run', ['cases' => $this->cases])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_ready_documents');
});

test('the key needs the evals:run ability', function () {
    [, $askOnly] = ApiKey::issue($this->workspace, 'bot', [ApiAbility::Ask]);

    $this->withToken($askOnly)->postJson('/api/v1/evals/run', ['cases' => $this->cases])->assertForbidden();
});

test('a run works one case per job, waits between chat calls and finishes with a summary', function () {
    $runId = $this->withToken($this->key)->postJson('/api/v1/evals/run', ['cases' => $this->cases])->json('data.id');
    $run = EvalRun::withoutGlobalScopes()->findOrFail($runId);

    (new RunEvalJob($run))->handle(app(EvalRunner::class), app(WebhookDispatcher::class));

    expect($run->refresh()->status)->toBe(EvalRunStatus::Running)
        ->and($run->results)->toHaveCount(1)
        ->and($run->started_at)->not->toBeNull();

    // The first case called the model, so the next one waits the delay.
    Queue::assertPushed(RunEvalJob::class, fn (RunEvalJob $job): bool => $job->delay !== null
        && now()->diffInSeconds($job->delay) >= 7);

    runEvalJobs($run);

    expect($run->refresh()->status)->toBe(EvalRunStatus::Completed)
        ->and($run->summary['retrieval_hit_rate'])->toEqual(1)
        ->and($run->summary['config']['delay_seconds'])->toEqual(8)
        ->and($run->finished_at)->not->toBeNull()
        // Eval conversations are removed after each case.
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(0);

    $this->withToken($this->key)->getJson('/api/v1/evals/'.$run->id)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed_cases', 2)
        ->assertJsonPath('data.results.0.hit', true)
        ->assertJsonPath('data.results.1.should_refuse', true)
        ->assertJsonMissingPath('data.callback_secret');
});

test('a finished run posts signed results to the callback and the eval.completed webhook', function () {
    $hook = Webhook::query()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://hooks.test/in', 'events' => ['eval.completed'], 'secret' => 'whsec_x']);

    $response = $this->withToken($this->key)->postJson('/api/v1/evals/run', ['cases' => $this->cases, 'callback_url' => 'https://n8n.test/webhook-waiting/7']);
    $run = EvalRun::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    $secret = $response->json('data.callback_secret');

    runEvalJobs($run);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhook->is($hook)
        && $job->event === 'eval.completed' && $job->data['eval_run']['status'] === 'completed'
        && ! isset($job->data['eval_run']['results']));

    Http::fake(['https://n8n.test/*' => Http::response('ok')]);
    (new SendEvalCallbackJob($run))->handle();

    Http::assertSent(function (Request $request) use ($secret, $run): bool {
        $body = $request->body();

        return $request->url() === 'https://n8n.test/webhook-waiting/7'
            && WebhookSigner::verify($body, $request->header('X-DocChat-Signature')[0], $request->header('X-DocChat-Timestamp')[0], $secret)
            && $request['event'] === 'eval.completed'
            && $request['data']['eval_run']['id'] === $run->id
            && count($request['data']['eval_run']['results']) === 2;
    });
});

test('a failed callback is retried', function () {
    $run = EvalRun::query()->create(['workspace_id' => $this->workspace->id, 'cases' => $this->cases, 'callback_url' => 'https://n8n.test/x', 'callback_secret' => 'whsec_y', 'status' => EvalRunStatus::Completed]);
    Http::fake(['*' => Http::response('down', 502)]);

    expect(fn () => (new SendEvalCallbackJob($run))->handle())->toThrow(RuntimeException::class, 'HTTP 502');
});

test('a run that crashes is marked failed and still reports back', function () {
    $run = EvalRun::query()->create(['workspace_id' => $this->workspace->id, 'cases' => $this->cases, 'callback_url' => 'https://n8n.test/x', 'callback_secret' => 'whsec_y']);

    (new RunEvalJob($run))->failed(new RuntimeException('Groq is down'));

    expect($run->refresh()->status)->toBe(EvalRunStatus::Failed)
        ->and($run->error)->toBe('Groq is down');

    Queue::assertPushed(SendEvalCallbackJob::class);
});

test('runs of another workspace are not found', function () {
    $other = User::factory()->create()->currentWorkspace();
    $run = EvalRun::query()->create(['workspace_id' => $other->id, 'cases' => $this->cases]);

    $this->withToken($this->key)->getJson('/api/v1/evals/'.$run->id)->assertNotFound();
});
