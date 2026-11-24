<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class DocumentUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The file is invalid.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $mime = $value->getMimeType();

        $allowedMimes = match ($extension) {
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
            default => [],
        };

        if ($allowedMimes === []) {
            $fail('Only PDF, TXT and Markdown files are allowed.');

            return;
        }

        if (! in_array($mime, $allowedMimes, true)) {
            $fail('The file content does not match its extension.');
        }
    }
}
