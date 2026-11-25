<?php

namespace App\Http\Requests\Api;

use App\Enums\DocumentSource;
use App\Rules\DocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** A PDF, TXT or Markdown file, up to 10 MB. */
            'file' => ['required', 'file', 'max:'.(int) config('docchat.ingestion.max_upload_kb'), new DocumentUpload],
            /** Shown in citations. Defaults to the file name. */
            'title' => ['nullable', 'string', 'max:255'],
            /** Where the file came from. Defaults to "api". */
            'source' => ['nullable', Rule::enum(DocumentSource::class)->except([DocumentSource::Upload])],
            /**
             * Your id for the file, such as a Google Drive file id. Uploading the same id again replaces the document.
             *
             * @example 1AbCdEfGhIjKlMnOp
             */
            'external_id' => ['nullable', 'string', 'max:255'],
            /**
             * Any JSON object. It comes back in the document.ready and document.failed webhooks.
             *
             * @example {"chat_id": 12345}
             */
            'meta' => ['nullable'],
        ];
    }

    /**
     * meta may be sent as a JSON string (multipart forms) or an object.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->input('meta');

        if (is_string($meta) && $meta !== '') {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $meta = $this->input('meta');

            if ($meta === null || $meta === '') {
                return;
            }

            $decoded = is_string($meta) ? json_decode($meta, true) : $meta;

            if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                $validator->errors()->add('meta', 'meta must be a JSON object.');
            } elseif (strlen((string) json_encode($decoded)) > 4096) {
                $validator->errors()->add('meta', 'meta must be at most 4 KB of JSON.');
            }
        });
    }
}
