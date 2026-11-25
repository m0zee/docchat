<?php

use App\Contracts\EmbeddingProvider;
use App\Jobs\IngestDocumentJob;
use App\Jobs\SendWebhookJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Ingestion\Chunker;
use App\Services\Ingestion\DocumentParser;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->webhook = Webhook::query()->create([
        'workspace_id' => $this->workspace->id,
        'url' => 'https://n8n.example.com/webhook/docchat',
        'events' => ['document.ready', 'document.failed'],
        'secret' => 'whsec_test',
    ]);
});

test('the secret is stored encrypted', function () {
    $raw = DB::table('webhooks')->value('secret');

    expect($raw)->not->toBe('whsec_test')->and($this->webhook->fresh()->secret)->toBe('whsec_test');
});

test('a delivery is signed over the raw body with a timestamp', function () {
    Http::fake(['*' => Http::response('', 200)]);

    (new SendWebhookJob($this->webhook, 'document.ready', ['document' => ['id' => 7]]))->handle();

    Http::assertSent(function (Request $request) {
        $body = $request->body();
        $data = json_decode($body, true);

        return $request->url() === 'https://n8n.example.com/webhook/docchat'
            && $request->header('X-DocChat-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, 'whsec_test')
            && $request->header('X-DocChat-Timestamp')[0] === (string) $data['timestamp']
            && $request->header('X-DocChat-Event')[0] === 'document.ready'
            && $data['data']['document']['id'] === 7
            && $data['workspace_id'] === $this->workspace->id;
    });

    expect($this->webhook->fresh()->last_status)->toBe(200)->and($this->webhook->fresh()->last_delivered_at)->not->toBeNull();
});

test('a failed delivery throws so the queue retries with backoff', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    $job = new SendWebhookJob($this->webhook, 'document.ready', []);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect($job->tries)->toBe(5)->and($job->backoff)->toBe([10, 60, 300, 900])
        ->and($this->webhook->fresh()->last_status)->toBe(503);
});

test('the receiver check accepts fresh signatures and rejects tampering and replays', function () {
    $now = 1_800_000_000;
    $body = json_encode(['event' => 'ping', 'timestamp' => $now]);
    $signature = WebhookSigner::sign($body, 'whsec_test');

    expect(WebhookSigner::verify($body, $signature, (string) $now, 'whsec_test', $now + 60))->toBeTrue()
        ->and(WebhookSigner::verify($body.' ', $signature, (string) $now, 'whsec_test', $now))->toBeFalse()
        ->and(WebhookSigner::verify($body, $signature, (string) $now, 'wrong', $now))->toBeFalse()
        ->and(WebhookSigner::verify($body, $signature, (string) $now, 'whsec_test', $now + 301))->toBeFalse()
        ->and(WebhookSigner::verify($body, $signature, (string) ($now + 5), 'whsec_test', $now))->toBeFalse();
});

test('ingestion fires document.ready with source, external id and meta', function () {
    Storage::fake('local');
    Queue::fake([SendWebhookJob::class]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    Storage::disk('local')->put('documents/a.txt', 'Opening hours are 9 to 5.');
    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/a.txt', 'original_filename' => 'a.txt',
        'source' => 'telegram', 'external_id' => 'tg-file-1', 'meta' => ['chat_id' => 555],
    ]);

    (new IngestDocumentJob($document))->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->event === 'document.ready'
        && $job->data['document']['status'] === 'ready'
        && $job->data['document']['external_id'] === 'tg-file-1'
        && (array) $job->data['document']['meta'] === ['chat_id' => 555]);
});

test('a failed ingestion fires document.failed, only to subscribed active webhooks', function () {
    Storage::fake('local');
    Queue::fake([SendWebhookJob::class]);
    Webhook::query()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://a.test', 'events' => ['document.ready'], 'secret' => 's']);
    Webhook::query()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://b.test', 'events' => ['document.failed'], 'secret' => 's', 'active' => false]);
    $document = Document::factory()->for($this->user)->create(['storage_path' => 'missing.txt']);

    (new IngestDocumentJob($document))->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));

    Queue::assertPushed(SendWebhookJob::class, 1);
    Queue::assertPushed(SendWebhookJob::class, fn ($job) => $job->event === 'document.failed' && $job->webhook->is($this->webhook));
});

test('owners manage webhooks; other workspaces are untouchable', function () {
    $component = Livewire::actingAs($this->user)->test('pages::workspace.webhooks')
        ->set('url', 'https://hooks.example.com/x')
        ->set('events', ['document.ready'])
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('whsec_');

    $created = Webhook::query()->where('url', 'https://hooks.example.com/x')->sole();
    $component->call('toggle', $created->id);
    expect($created->fresh()->active)->toBeFalse();

    $foreign = Webhook::query()->create(['workspace_id' => User::factory()->create()->currentWorkspace()->id, 'url' => 'https://x.test', 'events' => ['document.ready'], 'secret' => 's']);
    $component->call('delete', $foreign->id);
    expect($foreign->fresh())->not->toBeNull();

    $component->call('delete', $created->id);
    expect($created->fresh())->toBeNull();
});
