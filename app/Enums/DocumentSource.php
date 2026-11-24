<?php

namespace App\Enums;

enum DocumentSource: string
{
    case Upload = 'upload';
    case GoogleDrive = 'google_drive';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Upload',
            self::GoogleDrive => 'Google Drive',
            self::Telegram => 'Telegram',
            self::WhatsApp => 'WhatsApp',
            self::Api => 'API',
        };
    }
}
