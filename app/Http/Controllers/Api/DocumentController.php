<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DocumentService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Documents in the API key's workspace.
 */
class DocumentController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    /**
     * List documents, newest first.
     *
     * Filter by source and external_id to sync with another system, such as
     * a Google Drive folder.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'source' => ['nullable', Rule::enum(DocumentSource::class)],
            'external_id' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            /** Up to 100. Default 25. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $documents = Document::query()
            ->inWorkspace($this->current->require())
            ->withCount('chunks')
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['external_id'] ?? null, fn ($query, $id) => $query->where('external_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return DocumentResource::collection($documents);
    }

    /**
     * Upload a document.
     *
     * Ingestion runs in the background: the document starts as "pending" and
     * becomes "ready" or "failed". Poll GET /documents/{id} or subscribe to
     * the document.ready webhook. Sending the same source and external_id
     * again replaces the file and keeps the document id.
     *
     * @status 202
     */
    public function store(StoreDocumentRequest $request, DocumentService $documents): JsonResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $document = $documents->store(
            $file,
            $this->current->require(),
            null,
            $request->enum('source', DocumentSource::class) ?? DocumentSource::Api,
            $request->string('external_id')->toString() ?: null,
            $request->string('title')->toString() ?: null,
            $request->meta(),
        );

        return (new DocumentResource($document->refresh()))->response()->setStatusCode(202);
    }

    /**
     * Get a document and its ingestion status.
     */
    public function show(int $document): DocumentResource
    {
        return new DocumentResource(
            Document::query()->inWorkspace($this->current->require())->withCount('chunks')->findOrFail($document),
        );
    }

    /**
     * Delete a document with its file, chunks and vectors.
     */
    public function destroy(int $document, DocumentService $documents): JsonResponse
    {
        $documents->delete(Document::query()->inWorkspace($this->current->require())->findOrFail($document));

        return new JsonResponse(null, 204);
    }
}
