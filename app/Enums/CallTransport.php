<?php

namespace App\Enums;

enum CallTransport: string
{
    /** In the browser: the widget, a call link or the dashboard test call. */
    case Web = 'web';

    /** A real phone call through Twilio. */
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Browser',
            self::Phone => 'Phone',
        };
    }

    public function channel(): ConversationChannel
    {
        return match ($this) {
            self::Web => ConversationChannel::Voice,
            self::Phone => ConversationChannel::Phone,
        };
    }
}
