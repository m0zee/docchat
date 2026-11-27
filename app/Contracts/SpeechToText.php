<?php

namespace App\Contracts;

use App\Exceptions\SpeechException;
use App\Services\Voice\Transcript;

interface SpeechToText
{
    /**
     * Turn recorded speech into text.
     *
     * @param  string  $audio  the file's bytes
     * @param  string  $filename  with an extension the provider knows (webm, ogg, mp3, wav, m4a)
     * @param  string|null  $prompt  words the speaker is likely to use, so they are spelled right
     *
     * @throws SpeechException
     */
    public function transcribe(string $audio, string $filename, ?string $language = null, ?string $prompt = null): Transcript;
}
