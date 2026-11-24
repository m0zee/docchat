<?php

namespace App\Enums;

enum ApiAbility: string
{
    case Ask = 'ask';
    case DocumentsRead = 'documents:read';
    case DocumentsWrite = 'documents:write';
    case EvalsRun = 'evals:run';
    case ChannelsLink = 'channels:link';

    public function label(): string
    {
        return match ($this) {
            self::Ask => 'Ask questions',
            self::DocumentsRead => 'Read documents',
            self::DocumentsWrite => 'Upload and delete documents',
            self::EvalsRun => 'Run evals',
            self::ChannelsLink => 'Link chat channels',
        };
    }

    /**
     * Abilities an owner can give a secret workspace key.
     *
     * @return list<self>
     */
    public static function forLiveKeys(): array
    {
        return [self::Ask, self::DocumentsRead, self::DocumentsWrite, self::EvalsRun];
    }
}
