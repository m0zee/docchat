<?php

use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use Symfony\Component\Yaml\Yaml;

it('keeps retry_after above the ingestion job timeout', function () {
    $job = new IngestDocumentJob(new Document);

    expect((int) config('queue.connections.database.retry_after'))
        ->toBeGreaterThan($job->timeout);
});

it('gives the local worker a timeout that fits the ingestion job', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));
    $command = implode(' ', $compose['services']['worker']['command']);
    $job = new IngestDocumentJob(new Document);

    expect($command)->toContain('queue:listen');

    preg_match('/--timeout=(\d+)/', $command, $matches);

    expect((int) ($matches[1] ?? 60))->toBeGreaterThanOrEqual($job->timeout);
});
