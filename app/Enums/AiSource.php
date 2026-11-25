<?php

namespace App\Enums;

/**
 * Whose AI produced an answer: the app's own keys, the workspace's own
 * key, or the asker's browser.
 */
enum AiSource: string
{
    case Platform = 'platform';
    case Workspace = 'workspace';
    case Device = 'device';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'App keys',
            self::Workspace => 'Your own key',
            self::Device => 'On the device',
        };
    }
}
