<?php

namespace App\Enums;

enum CallStatus: string
{
    /** An outbound call link that nobody has opened yet. */
    case Waiting = 'waiting';
    /** An outbound phone call set for a later time. */
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Ringing = 'ringing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Scheduled => 'Scheduled',
            self::Queued => 'Queued',
            self::Ringing => 'Ringing',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::Failed => 'Failed',
            self::Canceled => 'Canceled',
        };
    }

    /**
     * Not started yet, so the owner can still cancel it.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Waiting, self::Scheduled, self::Queued], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::NoAnswer, self::Busy, self::Failed, self::Canceled], true);
    }

    /**
     * Twilio's call statuses: queued, ringing, in-progress, canceled,
     * completed, busy, failed, no-answer.
     */
    public static function fromTwilio(string $status): ?self
    {
        return match ($status) {
            'queued', 'initiated' => self::Queued,
            'ringing' => self::Ringing,
            'in-progress', 'answered' => self::InProgress,
            'completed' => self::Completed,
            'busy' => self::Busy,
            'no-answer' => self::NoAnswer,
            'failed' => self::Failed,
            'canceled' => self::Canceled,
            default => null,
        };
    }
}
