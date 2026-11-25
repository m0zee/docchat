<?php

use App\Services\Cache\AnswerCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('docchat:prune-answer-cache', function (AnswerCache $cache) {
    $this->info('Deleted '.$cache->prune().' stale or expired cached answers.');
})->purpose('Delete expired cached answers and answers from older document versions');

Schedule::command('docchat:prune-answer-cache')->daily();

Artisan::command('docchat:prune-voice-cache {--days=7}', function () {
    $disk = Storage::disk('local');
    $before = now()->subDays(max(0, (int) $this->option('days')))->getTimestamp();
    $deleted = 0;

    foreach ($disk->files('voice-cache') as $file) {
        if ($disk->lastModified($file) < $before) {
            $disk->delete($file);
            $deleted++;
        }
    }

    $this->info('Deleted '.$deleted.' cached voice clips.');
})->purpose('Delete cached Kokoro voice clips older than --days');

Schedule::command('docchat:prune-voice-cache')->daily();
