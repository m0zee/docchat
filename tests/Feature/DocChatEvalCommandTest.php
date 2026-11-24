<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Exceptions\ChatRateLimitedException;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

/**
 * Embeds each question to a vector that points at one test chunk, so the
 * test controls which chunk each question retrieves.
 */
class RoutedEmbeddingProvider extends FakeEmbeddingProvider
{
    /** @param array<string, array<int, float>> $routes */
    public function __construct(public array $routes) {}

    public function embedQuery(string $text): array
    {
        $this->queries[] = $text;

        return $this->routes[$text] ?? self::withSimilarity(0.0, axis: 50);
    }
}

beforeEach(function () {
    Sleep::fake();
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.rag.top_k' => 5]);

    $this->user = User::factory()->create(['email' => 'demo@docchat.test']);
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'handbook']);

    // Chunk on page 2 lies along axis 1, chunk on page 3 along axis 2.
    $axis = fn (int $i): array => tap(array_fill(0, 1536, 0.0), function (&$v) use ($i) {
        $v[$i] = 1.0;
    });
    Chunk::factory()->for($this->document)->create(['page_number' => 2, 'chunk_index' => 0, 'embedding' => $axis(1)]);
    Chunk::factory()->for($this->document)->create(['page_number' => 3, 'chunk_index' => 1, 'embedding' => $axis(2)]);

    $this->app->instance(EmbeddingProvider::class, new RoutedEmbeddingProvider([
        'How many items?' => $axis(1),          // hits page 2 (expected page 2)
        'What is the fee?' => $axis(2),         // retrieves page 3, but expected page 4: a miss
        'Who is the librarian?' => $axis(60),   // nothing similar: refusal
    ]));

    $this->chat = new FakeChatProvider(['You can borrow 15 items [1].']);
    $this->app->instance(ChatProvider::class, $this->chat);

    $this->file = tempnam(sys_get_temp_dir(), 'eval').'.json';
    file_put_contents($this->file, json_encode([
        ['question' => 'How many items?', 'expected_keywords' => ['15', 'items', 'renew'], 'expected_document' => 'handbook', 'expected_page' => 2],
        ['question' => 'What is the fee?', 'expected_keywords' => ['15'], 'expected_document' => 'handbook', 'expected_page' => 4],
        ['question' => 'Who is the librarian?', 'should_refuse' => true],
    ]));

    File::deleteDirectory(storage_path('app/evals'));
});

afterEach(function () {
    @unlink($this->file);
    File::deleteDirectory(storage_path('app/evals'));
});

function latestReport(): array
{
    $files = File::files(storage_path('app/evals'));

    expect($files)->toHaveCount(1);

    return json_decode(File::get($files[0]->getPathname()), true);
}

it('computes the four metrics and writes a JSON report', function () {
    $this->artisan('docchat:eval', ['file' => $this->file, '--delay' => 0])
        ->expectsOutputToContain('Retrieval hit rate')
        ->assertSuccessful();

    $report = latestReport();
    $summary = $report['summary'];

    expect($summary['cases'])->toBe(3)
        ->and($summary['retrieval_hit_rate'])->toEqual(0.5)
        ->and($summary['keyword_match_rate'])->toEqualWithDelta((2 / 3 + 1) / 2, 0.001)
        ->and($summary['correct_refusal_rate'])->toEqual(1)
        ->and($summary['false_refusal_rate'])->toEqual(0)
        ->and($summary['total_tokens'])->toBe(220)
        ->and($report['config'])->toHaveKeys(['embedding_model', 'chat_model', 'similarity_threshold', 'top_k', 'prompt_version'])
        ->and($report['cases'][2]['answer'])->toBe("I couldn't find that in your documents.");
});

it('never calls the chat model for a refusal case', function () {
    $this->artisan('docchat:eval', ['file' => $this->file, '--delay' => 0])->assertSuccessful();

    expect($this->chat->calls)->toHaveCount(2);
});

it('removes its conversations after the run', function () {
    $this->artisan('docchat:eval', ['file' => $this->file, '--delay' => 0])->assertSuccessful();

    expect(Conversation::count())->toBe(0);
});

it('waits between chat calls', function () {
    $this->artisan('docchat:eval', ['file' => $this->file, '--delay' => 3])->assertSuccessful();

    Sleep::assertSleptTimes(2);
});

it('sweeps thresholds without calling the chat model', function () {
    $this->artisan('docchat:eval', ['file' => $this->file, '--sweep' => '0.0,0.5,1.0'])
        ->expectsOutputToContain('Threshold')
        ->assertSuccessful();

    $rows = latestReport()['sweep'];

    expect($this->chat->calls)->toBe([])
        ->and($rows[0]['hit_rate'])->toEqual(0.5)
        ->and($rows[0]['correct_refusal_rate'])->toEqual(0)
        ->and($rows[1]['correct_refusal_rate'])->toEqual(1)
        ->and($rows[2]['hit_rate'])->toEqual(0.5)
        ->and($rows[2]['false_refusal_rate'])->toEqual(0);
});

it('lowers the hit rate and raises refusals as the threshold goes up', function () {
    config(['docchat.rag.top_k' => 5]);
    $this->app->instance(EmbeddingProvider::class, new RoutedEmbeddingProvider([
        // cosine 0.6 with the page-2 chunk, which lies along axis 1
        'How many items?' => FakeEmbeddingProvider::withSimilarity(0.8, axis: 1),
    ]));

    file_put_contents($this->file, json_encode([
        ['question' => 'How many items?', 'expected_keywords' => ['15'], 'expected_document' => 'handbook', 'expected_page' => 2],
    ]));

    $this->artisan('docchat:eval', ['file' => $this->file, '--sweep' => '0.5,0.7'])->assertSuccessful();

    $rows = latestReport()['sweep'];

    expect($rows[0]['hit_rate'])->toEqual(1)
        ->and($rows[1]['hit_rate'])->toEqual(0)
        ->and($rows[1]['false_refusal_rate'])->toEqual(1);
});

it('records a rate-limited case as an error and keeps going', function () {
    $this->app->instance(ChatProvider::class, new FakeChatProvider([], throwAfter: new ChatRateLimitedException('429')));

    $this->artisan('docchat:eval', ['file' => $this->file, '--delay' => 0])->assertSuccessful();

    expect(latestReport()['summary']['errors'])->toBe(2);
});

it('fails clearly for an unknown user or a bad file', function () {
    $this->artisan('docchat:eval', ['file' => $this->file, '--user' => 'nobody@example.com'])->assertFailed();
    $this->artisan('docchat:eval', ['file' => '/missing.json'])->assertFailed();
});
