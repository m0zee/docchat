<?php

namespace App\Services\Formatting;

enum MessageFormat: string
{
    case Plain = 'plain';
    case Markdown = 'markdown';
    case TelegramHtml = 'telegram_html';
    case WhatsApp = 'whatsapp';
    case Discord = 'discord';

    /**
     * Characters per message part, under each platform's limit
     * (Telegram 4096, WhatsApp 4096, Discord 2000).
     */
    public function limit(): int
    {
        return match ($this) {
            self::Discord => 1900,
            default => 4000,
        };
    }
}
