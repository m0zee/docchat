<?php

namespace App\Contracts;

use App\Exceptions\SpeechException;

interface TextToSpeech
{
    public function enabled(): bool;

    /**
     * Speak the text and return MP3 bytes.
     *
     * @throws SpeechException
     */
    public function speak(string $text): string;
}
