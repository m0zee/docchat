<?php

namespace App\Services\Voice;

use App\Exceptions\SpeechException;
use App\Models\VoiceClone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Speech in a cloned voice, from Kyutai's Pocket TTS (code MIT, weights
 * CC-BY-4.0), self-hosted as the optional "voice-clone" service. It runs on
 * a CPU and is free. Each request sends the speaker's consent recording as
 * the voice to copy; the WAV it returns is cached per clone, and those
 * clips are deleted when the clone is withdrawn or deleted.
 */
class PocketTtsVoice
{
    /** Pocket TTS serves one language per server, named like this. */
    public const SERVER_LANGUAGES = ['en' => 'english', 'es' => 'spanish', 'fr' => 'french', 'de' => 'german'];

    public static function configured(): bool
    {
        return filled(config('docchat.voice.clone.url'));
    }

    /**
     * The language the clone server speaks, as a two-letter code.
     */
    public static function language(): string
    {
        return VoiceConsent::language((string) config('docchat.voice.clone.language', 'en'));
    }

    /**
     * Whether this clone can speak in that language now.
     */
    public static function canSpeak(VoiceClone $clone, ?string $language = null): bool
    {
        return self::configured()
            && $clone->isReady()
            && $clone->language === self::language()
            && ($language === null || $language === self::language());
    }

    /**
     * @throws SpeechException
     */
    public function speak(VoiceClone $clone, string $text): string
    {
        return (string) Storage::disk('local')->get(self::path($this->clip($clone, $text)));
    }

    /**
     * Make (or find) the audio and return its clip id, for a URL to it.
     *
     * @throws SpeechException
     */
    public function clip(VoiceClone $clone, string $text): string
    {
        if (! self::configured()) {
            throw new SpeechException('Set DOCCHAT_CLONE_URL to use cloned voices.');
        }

        if (! $clone->isReady()) {
            throw new SpeechException('This voice clone is not ready.');
        }

        $text = SpokenText::limit(trim($text), max(1, (int) config('docchat.voice.clone.max_chars', 500)));

        if ($text === '') {
            throw new SpeechException('Nothing to say.');
        }

        $id = 'c'.$clone->id.'_'.hash('sha256', (string) $clone->sample_path.'|'.$text);
        $path = self::path($id);
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return $id;
        }

        $sample = $disk->get((string) $clone->sample_path);

        if ($sample === null || $sample === '') {
            throw new SpeechException('The voice recording is missing.');
        }

        try {
            $response = Http::timeout(max(5, (int) config('docchat.voice.clone.timeout', 60)))
                ->attach('voice_wav', $sample, 'voice.wav', ['Content-Type' => 'audio/wav'])
                ->post(rtrim((string) config('docchat.voice.clone.url'), '/').'/tts', ['text' => $text]);
        } catch (ConnectionException $exception) {
            throw new SpeechException('The voice clone server could not be reached.', retryable: true, previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw SpeechException::busy('The voice clone server is busy, try again in a moment.');
        }

        $audio = $response->body();

        if (! $response->successful() || ! str_starts_with($audio, 'RIFF')) {
            throw new SpeechException('The voice clone server failed: HTTP '.$response->status());
        }

        $disk->put($path, self::fixSizes($audio));

        return $id;
    }

    /**
     * Where a clip is kept: one folder per clone, so its clips can all go.
     */
    public static function path(string $id): string
    {
        $clone = preg_match('/^c(\d+)_/', $id, $match) === 1 ? $match[1] : '0';

        return self::folder((int) $clone).'/'.$id.'.wav';
    }

    public static function folder(int $cloneId): string
    {
        return 'voice-cache/clone-'.$cloneId;
    }

    public static function isClip(string $id): bool
    {
        return preg_match('/^c\d+_[a-f0-9]{64}$/', $id) === 1;
    }

    public static function forget(VoiceClone $clone): void
    {
        Storage::disk('local')->deleteDirectory(self::folder($clone->id));
    }

    /**
     * Pocket TTS streams its WAV, so the header carries placeholder sizes.
     * Write the real ones, which phones and some browsers need.
     */
    public static function fixSizes(string $wav): string
    {
        try {
            [$format, $data] = Wav::parse($wav);
        } catch (SpeechException) {
            return $wav;
        }

        return 'RIFF'.pack('V', 4 + 8 + strlen($format) + 8 + strlen($data)).'WAVE'
            .'fmt '.pack('V', strlen($format)).$format
            .'data'.pack('V', strlen($data)).$data;
    }
}
