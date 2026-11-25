<?php

namespace App\Services\Documents;

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Cache\AnswerCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentService
{
    public function createFromUpload(UploadedFile $file, Workspace $workspace, ?User $user = null): Document
    {
        return $this->store($file, $workspace, $user);
    }

    /**
     * Store a file and queue its ingestion. With an external_id, a second
     * upload for the same (workspace, source, external_id) replaces the file
     * of the existing document and re-ingests it, keeping its id.
     *
     * @param  array<string, mixed>  $meta  passed back in webhooks (for example a Telegram chat id)
     */
    public function store(
        UploadedFile $file,
        Workspace $workspace,
        ?User $user = null,
        DocumentSource $source = DocumentSource::Upload,
        ?string $externalId = null,
        ?string $title = null,
        array $meta = [],
    ): Document {
        $externalId = $externalId === null || trim($externalId) === '' ? null : Str::limit(trim($externalId), 255, '');
        $storagePath = $this->put($file, $workspace);

        $title = trim((string) $title) !== ''
            ? trim((string) $title)
            : (pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Untitled');

        $attributes = [
            'title' => Str::limit($title, 255, ''),
            'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
            'storage_path' => $storagePath,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'meta' => $meta === [] ? null : $meta,
            'status' => DocumentStatus::Pending,
            'error' => null,
        ];

        try {
            $document = $externalId === null
                ? $this->create($workspace, $user, $source, null, $attributes)
                : $this->replaceOrCreate($workspace, $user, $source, $externalId, $attributes);
        } catch (UniqueConstraintViolationException) {
            // Two uploads of the same external_id raced; the other one won.
            $document = $this->replaceOrCreate($workspace, $user, $source, (string) $externalId, $attributes);
        }

        IngestDocumentJob::dispatch($document);

        return $document;
    }

    public function delete(Document $document): void
    {
        $disk = Storage::disk('local');

        if ($document->storage_path !== '' && $disk->exists($document->storage_path)) {
            $disk->delete($document->storage_path);
        }

        DB::transaction(function () use ($document): void {
            $document->chunks()->delete();
            $document->delete();
            AnswerCache::invalidate($document->workspace_id);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(Workspace $workspace, ?User $user, DocumentSource $source, ?string $externalId, array $attributes): Document
    {
        return Document::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'source' => $source,
            'external_id' => $externalId,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function replaceOrCreate(Workspace $workspace, ?User $user, DocumentSource $source, string $externalId, array $attributes): Document
    {
        return DB::transaction(function () use ($workspace, $user, $source, $externalId, $attributes): Document {
            $existing = Document::query()
                ->inWorkspace($workspace)
                ->where('source', $source->value)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return $this->create($workspace, $user, $source, $externalId, $attributes);
            }

            $oldPath = $existing->storage_path;
            $existing->update($attributes);

            if ($oldPath !== $existing->storage_path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
            }

            return $existing;
        });
    }

    private function put(UploadedFile $file, Workspace $workspace): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $storagePath = $file->storeAs('documents/'.$workspace->id, Str::uuid().'.'.$extension, 'local');

        if ($storagePath === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        return $storagePath;
    }
}
