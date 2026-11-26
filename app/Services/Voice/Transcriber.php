<?php

namespace App\Services\Voice;

use App\Contracts\SpeechToText;
use App\Enums\DocumentStatus;
use App\Exceptions\SpeechException;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Speech to text for one workspace, within its daily share of the free
 * Groq quota.
 */
class Transcriber
{
    public const EXTENSIONS = ['webm', 'ogg', 'oga', 'opus', 'mp3', 'mpga', 'mpeg', 'wav', 'm4a', 'mp4', 'flac', 'aac'];

    public function __construct(private SpeechToText $stt) {}

    public function upload(Workspace $workspace, UploadedFile $file, ?string $language = null): Transcript
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        if ($extension === 'oga' || $extension === 'opus') {
            $extension = 'ogg';
        }

        if ($extension === 'aac') {
            $extension = 'm4a';
        }

        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'webm';
        }

        return $this->transcribe($workspace, (string) $file->get(), 'speech.'.$extension, $language, self::vocabulary($workspace));
    }

    /**
     * Words this workspace's callers are likely to say, for the speech
     * model's prompt: the call greeting and the titles of its documents.
     * Without it a short question like "tax 2026 freelance" comes back as
     * "text" or "attacks", and the search then finds nothing.
     */
    public static function vocabulary(Workspace $workspace): ?string
    {
        $titles = Document::query()
            ->inWorkspace($workspace)
            ->where('status', DocumentStatus::Ready)
            ->latest('id')
            ->limit(10)
            ->pluck('title')
            ->map(fn (mixed $title): string => self::words((string) $title))
            ->filter()
            ->unique()
            ->all();

        $text = trim(implode('. ', array_filter([
            trim((string) $workspace->setting('calls.greeting')),
            implode(', ', $titles),
        ])));

        return $text === '' ? null : Str::limit($text, 600, '');
    }

    /**
     * "202681113864992WithholdingTaxRatesCard2027" becomes "Withholding Tax Rates Card 2027".
     */
    private static function words(string $title): string
    {
        $spaced = (string) preg_replace(['/(?<=\p{Ll})(?=\p{Lu})/u', '/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', '/[_\-.]+/'], ' ', $title);
        $words = array_filter(preg_split('/\s+/', $spaced) ?: [], fn (string $word): bool => $word !== '' && ! preg_match('/^\d{6,}$/', $word));

        return implode(' ', $words);
    }

    public function transcribe(Workspace $workspace, string $audio, string $filename, ?string $language = null, ?string $prompt = null): Transcript
    {
        $key = 'stt:'.$workspace->id;
        $limit = max(1, (int) config('docchat.voice.stt.daily_limit_per_workspace'));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new SpeechException('This workspace used its speech recognition for today. Please type instead.', retryable: true);
        }

        RateLimiter::hit($key, 86400);

        return $this->stt->transcribe($audio, $filename, $language, $prompt);
    }
}
