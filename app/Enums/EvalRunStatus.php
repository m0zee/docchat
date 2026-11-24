<?php

namespace App\Enums;

enum EvalRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function finished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
