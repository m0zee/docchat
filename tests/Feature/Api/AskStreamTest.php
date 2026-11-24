<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ApiAbility;
use App\Exceptions\ChatRateLimitedException;
use App\Models\ApiKey;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\UsageEvent;
use App\Models\User;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider(['Refunds ', 'take 14 days [1].']));
    $this->user = User::factory()->create();
    $document = Document::factory()->ready()->for($this->user)->create(['title' => 'Policy']);
    Chunk::factory()->for($document)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    [$this->apiKey, $this->key] = ApiKey::issue($this->user->currentWorkspace(), 'bot', [ApiAbility::Ask]);
});

/**
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function sseEvents(string $body): array
{
    $events = [];

    foreach (array_filter(explode("\n\n", $body)) as $block) {
        preg_match('/^event: (.+)$/m', $block, $event);
        preg_match('/^data: (.+)$/m', $block, $data);
        $events[] = ['event' => $event[1], 'data' => json_decode($data[1], true)];
    }

    return $events;
}

test('the stream sends token events then one done event', function () {
    $response = $this->withToken($this->key)->get('/api/v1/ask/stream?question='.urlencode('How long do refunds take?').'&format=plain');

    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')->assertHeader('X-Accel-Buffering', 'no');

    $events = sseEvents($response->streamedContent());

    expect(array_column($events, 'event'))->toBe(['token', 'token', 'done'])
        ->and($events[0]['data'])->toBe(['text' => 'Refunds '])
        ->and($events[2]['data']['answer'])->toBe('Refunds take 14 days [1].')
        ->and($events[2]['data']['citations'][0]['title'])->toBe('Policy')
        ->and($events[2]['data']['formatted'][0])->toContain('Sources:')
        ->and($events[2]['data'])->toHaveKeys(['conversation_id', 'usage']);

    expect(UsageEvent::query()->sole()->api_key_id)->toBe($this->apiKey->id);
});

test('POST works the same way', function () {
    $response = $this->withToken($this->key)->post('/api/v1/ask/stream', ['question' => 'Refunds?']);

    expect(array_column(sseEvents($response->streamedContent()), 'event'))->toBe(['token', 'token', 'done']);
});

test('a busy model ends the stream with an error event', function () {
    app()->instance(ChatProvider::class, new FakeChatProvider(['Part'], throwAfter: new ChatRateLimitedException('429')));

    $events = sseEvents($this->withToken($this->key)->get('/api/v1/ask/stream?question=Refunds')->streamedContent());

    expect(array_column($events, 'event'))->toBe(['token', 'error'])
        ->and($events[1]['data']['code'])->toBe('model_busy');
});

test('input errors are JSON before the stream starts', function () {
    $this->withToken($this->key)->getJson('/api/v1/ask/stream')
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});
