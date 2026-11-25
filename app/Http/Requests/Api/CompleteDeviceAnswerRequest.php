<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CompleteDeviceAnswerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The conversation_id that POST /ask/device returned. */
            'conversation_id' => ['required', 'integer'],
            /** The one-time token that POST /ask/device returned. */
            'token' => ['required', 'string', 'size:40'],
            /** The answer the device wrote. Citation markers like [1] are mapped to sources. */
            'answer' => ['present', 'nullable', 'string', 'max:20000'],
            /** Set instead of an answer when the on-device model failed. */
            'error' => ['nullable', 'string', 'max:250'],
            /**
             * Which on-device engine wrote it, for your own logs.
             *
             * @example chrome
             */
            'engine' => ['nullable', 'string', 'in:chrome,webllm'],
        ];
    }
}
