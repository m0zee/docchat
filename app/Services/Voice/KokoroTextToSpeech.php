<?php

namespace App\Services\Voice;

use App\Contracts\TextToSpeech;
use App\Exceptions\SpeechException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Kokoro, an open (Apache-2.0) text-to-speech model, self-hosted with
 * Kokoro-FastAPI (the optional "kokoro" service in Docker Compose). It
 * returns MP3 directly, has no daily limit and costs nothing but CPU.
 * Audio is cached on the local disk, so repeated lines (greetings, fillers)
 * are instant.
 */
class KokoroTextToSpeech implements TextToSpeech
{
    public const FORMATS = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav'];

    public static function configured(): bool
    {
        return filled(config('docchat.voice.kokoro.url'));
    }

    /**
     * For voice notes in the chat bots: on when Kokoro is set up and voice
     * replies are on.
     */
    public function enabled(): bool
    {
        return (bool) config('docchat.voice.replies') && self::configured();
    }

    public function speak(string $text): string
    {
        if (! $this->enabled()) {
            throw new SpeechException('Voice replies are off. Set DOCCHAT_VOICE_REPLIES=true and DOCCHAT_KOKORO_URL.');
        }

        return $this->speakAs($text, (string) config('docchat.voice.kokoro.voice', 'af_heart'));
    }

    /**
     * @param  'mp3'|'wav'  $format
     *
     * @throws SpeechException
     */
    public function speakAs(string $text, string $voice, float $speed = 1.0, string $format = 'mp3'): string
    {
        return (string) Storage::disk('local')->get(self::path($this->clip($text, $voice, $speed, $format), $format));
    }

    /**
     * Make (or find) the audio and return its clip id, for a URL to it.
     *
     * @param  'mp3'|'wav'  $format
     *
     * @throws SpeechException
     */
    public function clip(string $text, string $voice, float $speed = 1.0, string $format = 'mp3'): string
    {
        if (! self::configured()) {
            throw new SpeechException('Set DOCCHAT_KOKORO_URL to use the Kokoro voice.');
        }

        $text = SpokenText::limit(trim($text), max(1, (int) config('docchat.voice.kokoro.max_chars', 600)));

        if ($text === '') {
            throw new SpeechException('Nothing to say.');
        }

        $speed = round(min(1.5, max(0.6, $speed)), 2);
        $id = hash('sha256', $voice.'|'.number_format($speed, 2).'|'.$text);
        $path = self::path($id, $format);
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return $id;
        }

        try {
            $response = Http::timeout(max(5, (int) config('docchat.voice.kokoro.timeout', 30)))
                ->post(rtrim((string) config('docchat.voice.kokoro.url'), '/').'/v1/audio/speech', [
                    'model' => 'kokoro',
                    'input' => $text,
                    'voice' => $voice,
                    'response_format' => $format,
                    'speed' => $speed,
                    'stream' => false,
                ]);
        } catch (ConnectionException $exception) {
            throw new SpeechException('The Kokoro voice could not be reached.', retryable: true, previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw SpeechException::busy('The Kokoro voice is busy, try again in a moment.');
        }

        if (! $response->successful() || $response->body() === '') {
            throw new SpeechException('The Kokoro voice failed: HTTP '.$response->status());
        }

        $disk->put($path, $response->body());

        return $id;
    }

    public static function path(string $id, string $format = 'mp3'): string
    {
        return 'voice-cache/'.$id.'.'.$format;
    }
}
