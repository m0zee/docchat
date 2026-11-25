<?php

namespace App\Services\Voice;

use App\Contracts\TextToSpeech;
use App\Exceptions\SpeechException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Spoken replies for voice notes: Groq's Orpheus voices (200 characters a
 * request, WAV only), joined and turned into MP3 with ffmpeg, because
 * Telegram and WhatsApp do not play WAV as audio.
 */
class GroqTextToSpeech implements TextToSpeech
{
    public const MAX_REQUEST_CHARS = 200;

    public function enabled(): bool
    {
        return (bool) config('docchat.voice.replies') && trim((string) config('docchat.voice.tts.api_key')) !== '';
    }

    public function speak(string $text): string
    {
        if (! $this->enabled()) {
            throw new SpeechException('Voice replies are off. Set DOCCHAT_VOICE_REPLIES=true and GROQ_API_KEY.');
        }

        $text = SpokenText::limit($text, max(1, (int) config('docchat.voice.tts.max_chars')));
        $pieces = self::split($text);

        if ($pieces === []) {
            throw new SpeechException('Nothing to say.');
        }

        $wav = Wav::join(array_map(fn (string $piece): string => $this->request($piece), $pieces));

        return $this->toMp3($wav);
    }

    /**
     * Split into pieces of at most 200 characters, at sentence ends where
     * possible, then at spaces.
     *
     * @return list<string>
     */
    public static function split(string $text, int $max = self::MAX_REQUEST_CHARS): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            foreach (self::words($sentence, $max) as $part) {
                $candidate = $current === '' ? $part : $current.' '.$part;

                if (mb_strlen($candidate) <= $max) {
                    $current = $candidate;

                    continue;
                }

                if ($current !== '') {
                    $pieces[] = $current;
                }

                $current = $part;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * A sentence cut at spaces into parts of at most $max characters.
     *
     * @return list<string>
     */
    private static function words(string $sentence, int $max): array
    {
        $sentence = trim($sentence);

        if ($sentence === '') {
            return [];
        }

        if (mb_strlen($sentence) <= $max) {
            return [$sentence];
        }

        $parts = [];
        $current = '';

        foreach (preg_split('/\s+/u', $sentence) ?: [] as $word) {
            $word = mb_substr($word, 0, $max);
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) > $max) {
                $parts[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function request(string $piece): string
    {
        $config = (array) config('docchat.voice.tts');

        try {
            $response = Http::withToken((string) $config['api_key'])
                ->timeout(30)
                ->post(rtrim((string) $config['base_url'], '/').'/audio/speech', [
                    'model' => (string) $config['model'],
                    'voice' => (string) $config['voice'],
                    'input' => $piece,
                    'response_format' => 'wav',
                ]);
        } catch (ConnectionException $exception) {
            throw new SpeechException('Text to speech could not be reached.', retryable: true, previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw SpeechException::busy('Text to speech is busy, try again later.');
        }

        if (! $response->successful()) {
            throw new SpeechException('Text to speech failed: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return $response->body();
    }

    private function toMp3(string $wav): string
    {
        $result = Process::input($wav)->timeout(60)->run([
            (string) config('docchat.voice.tts.ffmpeg', 'ffmpeg'),
            '-hide_banner', '-loglevel', 'error',
            '-f', 'wav', '-i', 'pipe:0',
            '-ac', '1', '-b:a', '48k', '-f', 'mp3', 'pipe:1',
        ]);

        if (! $result->successful() || $result->output() === '') {
            throw new SpeechException('ffmpeg could not make the MP3. Is ffmpeg installed? '.trim($result->errorOutput()));
        }

        return $result->output();
    }
}
