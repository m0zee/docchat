<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentService
{
    public function createFromUpload(UploadedFile $file, Workspace $workspace, ?User $user = null): Document
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;
        $storagePath = $file->storeAs('documents/'.$workspace->id, $filename, 'local');

        if ($storagePath === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Untitled';

        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'title' => Str::limit($title, 255, ''),
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'status' => DocumentStatus::Pending,
        ]);

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
        });
    }
}
