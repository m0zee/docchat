<?php

use App\Models\User;
use App\Rules\DocumentUpload;
use App\Services\Documents\DocumentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
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

        try {
            $service->createFromUpload($this->file, $user);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', __('The document could not be uploaded. Please try again.'));

            return;
        }

        $this->reset('file');

        Flux::toast(variant: 'success', text: __('Document uploaded. Processing has started.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <div class="mb-6">
        <flux:heading size="xl">{{ __('Documents') }}</flux:heading>
        <flux:subheading>
            {{ __('Upload a PDF, TXT or Markdown file. DocChat will chunk and embed it in the background.') }}
        </flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="upload" class="space-y-4">
            <div>
                <input
                    type="file"
                    wire:model="file"
                    accept=".pdf,.txt,.md"
                    class="block w-full cursor-pointer rounded-lg border border-zinc-300 bg-white text-sm text-zinc-700 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600"
                />

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

    <div class="mt-8 rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
        <flux:icon name="document-plus" class="mx-auto size-8 text-zinc-400" />
        <flux:text class="mt-2">{{ __('Your documents will appear here.') }}</flux:text>
    </div>
</section>
