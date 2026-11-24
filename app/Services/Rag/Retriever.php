<?php

namespace App\Services\Rag;

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Retriever
{
    public function __construct(private EmbeddingProvider $embeddings) {}

    /**
     * Find the user's chunks closest to the question, best first.
     *
     * @param  list<int>|null  $documentIds  null searches all of the user's ready documents
     * @return list<RetrievedChunk>
     */
    public function retrieve(User $user, string $question, ?array $documentIds = null): array
    {
        if ($documentIds === []) {
            return [];
        }

        return $this->search($user, $this->embeddings->embedQuery($question), $documentIds);
    }

    /**
     * Same search with a vector that is already embedded (used by the eval sweep).
     *
     * @param  array<int, float>  $vector
     * @param  list<int>|null  $documentIds
     * @return list<RetrievedChunk>
     */
    public function search(User $user, array $vector, ?array $documentIds = null, ?float $threshold = null): array
    {
        $topK = max(1, (int) config('docchat.rag.top_k'));
        $threshold ??= (float) config('docchat.rag.similarity_threshold');

        $rows = DB::transaction(function () use ($user, $vector, $documentIds, $topK, $threshold) {
            // A filtered HNSW scan can stop before it finds top_k matching rows.
            // pgvector 0.8 iterative scans keep walking the index until it does.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET LOCAL hnsw.iterative_scan = relaxed_order');
            }

            return Chunk::query()
                ->ownedBy($user)
                ->join('documents', 'documents.id', '=', 'chunks.document_id')
                ->where('documents.status', DocumentStatus::Ready->value)
                ->when($documentIds !== null, fn ($query) => $query->whereIn('chunks.document_id', $documentIds))
                ->select([
                    'chunks.id',
                    'chunks.document_id',
                    'chunks.page_number',
                    'chunks.content',
                    'documents.title as document_title',
                ])
                ->selectVectorDistance('chunks.embedding', $vector, 'distance')
                ->whereVectorDistanceLessThan('chunks.embedding', $vector, 1 - $threshold)
                ->orderByVectorDistance('chunks.embedding', $vector)
                ->limit($topK)
                ->toBase()
                ->get();
        });

        $chunks = $rows->map(fn (object $row): RetrievedChunk => new RetrievedChunk(
            chunkId: (int) $row->id,
            documentId: (int) $row->document_id,
            documentTitle: (string) $row->document_title,
            pageNumber: (int) $row->page_number,
            content: (string) $row->content,
            similarity: round(1 - (float) $row->distance, 6),
        ))->all();

        // relaxed_order may return rows slightly out of order.
        usort($chunks, fn (RetrievedChunk $a, RetrievedChunk $b): int => $b->similarity <=> $a->similarity);

        return $chunks;
    }
}
