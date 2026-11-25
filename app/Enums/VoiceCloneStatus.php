<?php

namespace App\Enums;

enum VoiceCloneStatus: string
{
    /** Waiting for the speaker to record the consent statement. */
    case Waiting = 'waiting';

    /** Consent heard; the recording is the voice sample. */
    case Ready = 'ready';

    /** Too many recordings without a valid consent statement. */
    case Failed = 'failed';

    /** The speaker or an owner withdrew it; the recording is deleted. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting for consent',
            self::Ready => 'Ready',
            self::Failed => 'Consent not heard',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
