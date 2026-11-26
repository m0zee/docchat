<?php

namespace Tests\Support;

use App\Contracts\SpeechToText;
use App\Services\Voice\Transcript;
use Throwable;

class FakeSpeechToText implements SpeechToText
{
    /** @var list<array{bytes: int, filename: string, language: string|null, prompt: string|null}> */
    public array $calls = [];

    public function __construct(public string $text = 'When do you open?', public ?Throwable $throw = null, public ?string $detected = 'en') {}

    public function transcribe(string $audio, string $filename, ?string $language = null, ?string $prompt = null): Transcript
    {
        $this->calls[] = ['bytes' => strlen($audio), 'filename' => $filename, 'language' => $language, 'prompt' => $prompt];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new Transcript($this->text, 2.5, $this->detected);
    }
}
