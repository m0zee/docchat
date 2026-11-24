<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Rules\DocumentUpload;
use App\Services\Documents\DocumentService;
use App\Support\FileSize;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Documents')] class extends Component {
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    public function upload(DocumentService $service): void
    {
        $this->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) config('docchat.ingestion.max_upload_kb'),
                new DocumentUpload(),
            ],
        ]);

        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $workspace = $this->workspace();
        Gate::authorize('create', [Document::class, $workspace]);

        try {
            $service->createFromUpload($this->file, $workspace, $user);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', __('The document could not be uploaded. Please try again.'));

            return;
        }

        $this->reset('file');

        Flux::toast(variant: 'success', text: __('Document uploaded. Processing has started.'));
    }

    public function delete(int $documentId, DocumentService $service): void
    {
        $document = Document::query()->inWorkspace($this->workspace())->findOrFail($documentId);

        Gate::authorize('delete', $document);

        $service->delete($document);

        Flux::toast(variant: 'success', text: __('Document deleted.'));
    }

    /**
     * @return Collection<int, Document>
     */
    #[Computed]
    public function documents(): Collection
    {
        return Document::query()->inWorkspace($this->workspace())->withCount('chunks')->latest()->get();
    }

    private function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    #[Computed]
    public function hasActiveDocuments(): bool
    {
        return $this->documents->contains(
            fn (Document $document): bool => in_array(
                $document->status,
                [DocumentStatus::Pending, DocumentStatus::Processing],
                true,
            ),
        );
    }
}; ?>

<section class="w-full max-w-5xl">
    <div class="mb-6">
        <flux:heading size="xl">{{ __('Documents') }}</flux:heading>
        <flux:subheading>
            {{ __('Upload a PDF, TXT or Markdown file. DocChat will chunk and embed it in the background.') }}
        </flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="upload" class="space-y-4">
            <div>
                <label
                    x-data="{ over: false }"
                    x-on:dragover.prevent="over = true"
                    x-on:dragleave.prevent="over = false"
                    x-on:drop.prevent="over = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change'))"
                    x-bind:class="over ? 'border-zinc-500 bg-zinc-50 dark:border-zinc-400 dark:bg-zinc-800' : 'border-zinc-300 dark:border-zinc-600'"
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition"
                    data-dropzone
                >
                    <flux:icon name="arrow-up-tray" class="size-7 text-zinc-400" />
                    <span class="text-sm font-medium">{{ __('Drop a file here, or click to choose one') }}</span>
                    @if ($file)
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $file->getClientOriginalName() }}</span>
                    @endif
                    <input
                        x-ref="input"
                        type="file"
                        wire:model="file"
                        accept=".pdf,.txt,.md"
                        class="sr-only"
                    />
                </label>

                @error('file')
                    <flux:text class="mt-2 text-sm !text-red-600 dark:!text-red-400">{{ $message }}</flux:text>
                @enderror

                <flux:text class="mt-2 text-sm">
                    {{ __('PDF, TXT or MD. Maximum 10 MB.') }}
                </flux:text>
            </div>

            <div class="flex items-center gap-3">
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="file,upload"
                >
                    {{ __('Upload') }}
                </flux:button>

                <span wire:loading wire:target="file" class="text-sm text-zinc-500">
                    {{ __('Uploading…') }}
                </span>
            </div>
        </form>
    </div>

    <div class="mt-8">
        <div class="mb-3 flex items-end justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Your documents') }}</flux:heading>
                <flux:text class="mt-1">{{ $this->documents->count() }} {{ __('documents') }}</flux:text>
            </div>
        </div>

        @if ($this->documents->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon name="document-plus" class="mx-auto size-8 text-zinc-400" />
                <flux:text class="mt-2">{{ __('Your documents will appear here.') }}</flux:text>
            </div>
        @else
            <div @if ($this->hasActiveDocuments) wire:poll.3s @endif>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Document') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Pages') }}</flux:table.column>
                        <flux:table.column>{{ __('Chunks') }}</flux:table.column>
                        <flux:table.column>{{ __('Size') }}</flux:table.column>
                        <flux:table.column>{{ __('Uploaded') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->documents as $document)
                            <flux:table.row :key="$document->id">
                                <flux:table.cell variant="strong" class="whitespace-normal">
                                    <div>{{ $document->title }}</div>
                                    <div class="text-xs font-normal text-zinc-500">{{ $document->original_filename }}</div>

                                    @if ($document->status === DocumentStatus::Failed && $document->error)
                                        <div class="mt-1 text-xs font-normal text-red-600 dark:text-red-400">
                                            {{ $document->error }}
                                        </div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$document->status->badgeColor()">
                                        {{ $document->status->label() }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>{{ $document->page_count ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $document->chunks_count }}</flux:table.cell>
                                <flux:table.cell>{{ FileSize::format($document->size_bytes) }}</flux:table.cell>
                                <flux:table.cell>{{ $document->created_at?->diffForHumans() }}</flux:table.cell>

                                <flux:table.cell class="text-end">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        wire:click="delete({{ $document->id }})"
                                        wire:confirm="{{ __('Delete this document and all of its chunks?') }}"
                                    >
                                        {{ __('Delete') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
</section>
