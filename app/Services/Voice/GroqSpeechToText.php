<?php

namespace App\Services\Voice;

use App\Contracts\SpeechToText;
use App\Exceptions\SpeechException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Groq's OpenAI-compatible /audio/transcriptions with Whisper. The free tier
 * allows 20 requests a minute, 2,000 a day and 25 MB a file.
 */
class GroqSpeechToText implements SpeechToText
{
    public function transcribe(string $audio, string $filename, ?string $language = null): Transcript
    {
        $config = (array) config('docchat.voice.stt');
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($apiKey === '') {
            throw new SpeechException('Set GROQ_API_KEY in .env for speech recognition.');
        }

        $fields = [
            'model' => (string) ($config['model'] ?? 'whisper-large-v3-turbo'),
            'response_format' => 'verbose_json',
            'temperature' => '0',
        ];

        $language ??= ($config['language'] ?? null) ?: null;

        if (is_string($language) && $language !== '') {
            $fields['language'] = $language;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(30)
                ->attach('file', $audio, $filename)
                ->post(rtrim((string) $config['base_url'], '/').'/audio/transcriptions', $fields);
        } catch (ConnectionException $exception) {
            throw new SpeechException('Speech recognition could not be reached.', retryable: true, previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw SpeechException::busy();
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());

            throw new SpeechException('Speech recognition failed: '.$message);
        }

        $duration = $response->json('duration');

        return new Transcript(
            text: trim((string) $response->json('text')),
            seconds: is_numeric($duration) ? (float) $duration : null,
            language: is_string($response->json('language')) ? $response->json('language') : null,
        );
    }
}
