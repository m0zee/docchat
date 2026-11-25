<?php

namespace App\Enums;

/**
 * Providers a workspace can bring its own free API key for.
 */
enum AiProvider: string
{
    case Groq = 'groq';
    case Gemini = 'gemini';

    public function label(): string
    {
        return match ($this) {
            self::Groq => 'Groq',
            self::Gemini => 'Google Gemini',
        };
    }

    /**
     * Where the owner creates a free key.
     */
    public function keyUrl(): string
    {
        return match ($this) {
            self::Groq => 'https://console.groq.com/keys',
            self::Gemini => 'https://aistudio.google.com/apikey',
        };
    }
}
