<?php

namespace App\Enums;

enum ConversationChannel: string
{
    case Web = 'web';
    case Api = 'api';
    case Widget = 'widget';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Discord = 'discord';
    case Eval = 'eval';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Api => 'API',
            self::Widget => 'Widget',
            self::Telegram => 'Telegram',
            self::WhatsApp => 'WhatsApp',
            self::Discord => 'Discord',
            self::Eval => 'Eval',
        };
    }

    /**
     * Chat apps a workspace can be linked to, so one shared bot serves many workspaces.
     *
     * @return list<self>
     */
    public static function linkable(): array
    {
        return [self::Telegram, self::WhatsApp, self::Discord];
    }

    public function isLinkable(): bool
    {
        return in_array($this, self::linkable(), true);
    }
}
