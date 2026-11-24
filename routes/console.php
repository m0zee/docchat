<?php

use App\Services\Cache\AnswerCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('docchat:prune-answer-cache', function (AnswerCache $cache) {
    $this->info('Deleted '.$cache->prune().' stale or expired cached answers.');
})->purpose('Delete expired cached answers and answers from older document versions');

Schedule::command('docchat:prune-answer-cache')->daily();
