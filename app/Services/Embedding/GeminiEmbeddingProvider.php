<?php

namespace App\Services\Embedding;

use App\Contracts\EmbeddingProvider;
use App\Exceptions\EmbeddingException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class GeminiEmbeddingProvider implements EmbeddingProvider
{
    public function embedDocuments(array $texts, string $title = ''): array
    {
        $prefix = str_replace(
            '{title}',
            $title,
            (string) config('docchat.embedding.task_prefixes.document'),
        );

        return $this->embed(array_map(
            fn (string $text): string => $prefix.$text,
            $texts,
        ));
    }

    public function embedQuery(string $text): array
    {
        $prefix = (string) config('docchat.embedding.task_prefixes.query');

        return $this->embed([$prefix.$text])[0];
    }

    /**
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     */
    private function embed(array $texts): array
    {
        $apiKey = (string) config('docchat.embedding.api_key');

        if ($apiKey === '') {
            throw new EmbeddingException('Set GEMINI_API_KEY in .env.');
        }

        $model = trim((string) config('docchat.embedding.model'));

        if ($model === '') {
            throw new EmbeddingException('Set DOCCHAT_EMBEDDING_MODEL in .env.');
        }

        $modelId = str_starts_with($model, 'models/') ? substr($model, 7) : $model;
        $dimensions = max(1, (int) config('docchat.embedding.dimensions'));
        $batchSize = max(1, (int) config('docchat.embedding.batch_size'));
        $url = rtrim((string) config('docchat.embedding.base_url'), '/')
            .'/models/'.$modelId.':batchEmbedContents';

        $vectors = [];

        foreach (array_chunk($texts, $batchSize) as $batch) {
            $requests = array_map(fn (string $text): array => [
                'model' => 'models/'.$modelId,
                'content' => ['parts' => [['text' => $text]]],
                'embedContentConfig' => ['outputDimensionality' => $dimensions],
            ], $batch);

            $response = $this->postWithRetries($url, $apiKey, ['requests' => $requests]);
            $embeddings = $response->json('embeddings');

            if (! is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new EmbeddingException('Gemini returned an unexpected number of embeddings.');
            }

            foreach ($embeddings as $embedding) {
                $values = is_array($embedding) ? ($embedding['values'] ?? null) : null;

                if (! is_array($values) || count($values) !== $dimensions) {
                    throw new EmbeddingException('Gemini returned an embedding with unexpected dimensions.');
                }

                $vectors[] = array_map(floatval(...), $values);
            }
        }

        return $vectors;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWithRetries(string $url, string $apiKey, array $payload): Response
    {
        $maxAttempts = max(1, (int) config('docchat.embedding.max_retries'));
        $baseDelay = max(0, (int) config('docchat.embedding.retry_base_ms'));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->timeout(60)
                ->post($url, $payload);

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;

            if (! $retryable || $attempt === $maxAttempts) {
                throw new EmbeddingException('Gemini request failed with status '.$status.'.');
            }

            $retryAfter = $response->header('Retry-After');
            $delay = is_numeric($retryAfter)
                ? max(0, (int) $retryAfter * 1000)
                : $baseDelay * (2 ** ($attempt - 1)) + random_int(0, 250);

            Sleep::for($delay)->milliseconds();
        }

        throw new EmbeddingException('Gemini request failed.');
    }
}
