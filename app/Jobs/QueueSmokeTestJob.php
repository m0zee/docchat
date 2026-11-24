<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueSmokeTestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        logger('queue-smoke-ok');
    }
}
