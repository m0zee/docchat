<?php

namespace App\Services\Voice;

use App\Contracts\SpeechToText;
use App\Exceptions\SpeechException;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

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

        return $this->transcribe($workspace, (string) $file->get(), 'speech.'.$extension, $language);
    }

    public function transcribe(Workspace $workspace, string $audio, string $filename, ?string $language = null): Transcript
    {
        $key = 'stt:'.$workspace->id;
        $limit = max(1, (int) config('docchat.voice.stt.daily_limit_per_workspace'));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new SpeechException('This workspace used its speech recognition for today. Please type instead.', retryable: true);
        }

        RateLimiter::hit($key, 86400);

        return $this->stt->transcribe($audio, $filename, $language);
    }
}
