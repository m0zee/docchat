<?php

namespace App\Http\Requests\Api;

class VoiceAskRequest extends AskRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['question']);

        return [
            /** The voice note: ogg, webm, mp3, wav or m4a, up to 10 MB. */
            'audio' => ['required', 'file', 'max:'.max(1, (int) config('docchat.voice.stt.max_upload_kb'))],
            /** Optional language hint for speech recognition (ISO 639-1, like en or ur). */
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2}$/'],
        ] + $rules;
    }
}
