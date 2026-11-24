<?php

namespace App\Jobs;

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentParsingException;
use App\Exceptions\EmbeddingException;
use App\Models\Document;
use App\Services\Ingestion\Chunker;
use App\Services\Ingestion\DocumentParser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class IngestDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * A document deleted before the job runs needs no ingestion.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Document $document) {}

    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    public function handle(
        DocumentParser $parser,
        Chunker $chunker,
        EmbeddingProvider $embeddings,
    ): void {
        $document = $this->document->refresh();

        $document->update([
            'status' => DocumentStatus::Processing,
            'error' => null,
        ]);

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($document->storage_path)) {
                throw new DocumentParsingException('The uploaded file is missing.');
            }

            $extension = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));
            $parsed = $parser->parse($disk->path($document->storage_path), $extension);
            $chunks = $chunker->chunk($parsed->pages);

            if ($chunks === []) {
                throw new DocumentParsingException(
                    $extension === 'pdf'
                        ? 'No extractable text (scanned PDF?)'
                        : 'No extractable text in the uploaded file.',
                );
            }

            $vectors = $embeddings->embedDocuments(
                array_map(fn ($chunk): string => $chunk->content, $chunks),
                $document->title,
            );

            if (count($vectors) !== count($chunks)) {
                throw new EmbeddingException('Gemini returned an unexpected number of embeddings.');
            }

            DB::transaction(function () use ($document, $parsed, $chunks, $vectors): void {
                $document->chunks()->delete();

                foreach ($chunks as $index => $chunk) {
                    $document->chunks()->create([
                        'workspace_id' => $document->workspace_id,
                        'page_number' => $chunk->pageNumber,
                        'chunk_index' => $chunk->chunkIndex,
                        'content' => $chunk->content,
                        'token_estimate' => $chunk->tokenEstimate,
                        'embedding' => $vectors[$index],
                    ]);
                }

                $document->update([
                    'page_count' => $parsed->pageCount,
                    'embedding_model' => (string) config('docchat.embedding.model'),
                    'status' => DocumentStatus::Ready,
                    'error' => null,
                ]);
            });
        } catch (Throwable $exception) {
            if (self::isPermanent($exception)) {
                // Retrying can't fix a scanned PDF or a rejected API key:
                // record it now and stop the queue from trying again.
                $this->markFailed($document, $exception);
                $this->fail($exception);

                return;
            }

            // Transient error: keep the document "processing" so the badge
            // keeps polling. failed() records the error after the last try.
            throw $exception;
        }
    }

    public static function isPermanent(Throwable $exception): bool
    {
        return $exception instanceof DocumentParsingException
            || ($exception instanceof EmbeddingException && ! $exception->retryable);
    }

    public function failed(?Throwable $exception): void
    {
        $document = $this->document->fresh();

        if ($document === null) {
            return;
        }

        $this->markFailed($document, $exception);
    }

    private function markFailed(Document $document, ?Throwable $exception): void
    {
        $message = match (true) {
            $exception instanceof DocumentParsingException,
            $exception instanceof EmbeddingException => (string) $exception->getMessage(),
            default => 'The document could not be processed.',
        };

        $document->update([
            'status' => DocumentStatus::Failed,
            'error' => Str::limit($message, 500),
        ]);
    }
}
