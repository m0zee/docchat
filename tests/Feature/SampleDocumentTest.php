<?php

use App\Enums\DocumentStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Ingestion\DocumentParser;
use Database\Seeders\SampleDocumentSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('ships a sample PDF with text on all six pages', function () {
    $parsed = app(DocumentParser::class)->parse(
        database_path('seeders/samples/'.SampleDocumentSeeder::FILE),
        'pdf',
    );

    expect($parsed->pageCount)->toBe(6)
        ->and(array_map(fn ($page) => $page->pageNumber, $parsed->pages))->toBe([1, 2, 3, 4, 5, 6])
        ->and($parsed->pages[2]->text)->toContain('25 cents per day');
});

it('seeds the demo user and queues the sample for ingestion once', function () {
    Queue::fake();
    Storage::fake('local');

    $this->seed(SampleDocumentSeeder::class);
    $this->seed(SampleDocumentSeeder::class);

    $user = User::query()->where('email', SampleDocumentSeeder::EMAIL)->sole();
    $document = Document::query()->sole();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($document->user_id)->toBe($user->id)
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->title)->toBe('harbor-point-library-handbook');

    Storage::disk('local')->assertExists($document->storage_path);
    Queue::assertPushed(IngestDocumentJob::class, 1);
});
