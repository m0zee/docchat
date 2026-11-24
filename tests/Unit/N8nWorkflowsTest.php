<?php

/**
 * The exported n8n workflows are committed to a public repository, so they
 * must load in n8n and carry no credentials or secrets.
 */
function n8nWorkflowFiles(): array
{
    $files = glob(dirname(__DIR__, 2).'/n8n/*.json') ?: [];

    return array_combine(array_map('basename', $files), array_map(fn (string $file): array => [$file], $files));
}

test('there are exported workflows', function () {
    expect(n8nWorkflowFiles())->toHaveKeys(['drive-ingest.json', 'drive-sync-deletes.json', 'nightly-eval.json']);
});

test('the workflow is valid and well formed', function (string $file) {
    $workflow = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    expect($workflow)->toHaveKeys(['id', 'name', 'nodes', 'connections'])
        ->and($workflow['active'] ?? false)->toBeFalse();

    $names = array_column($workflow['nodes'], 'name');
    expect($names)->toBe(array_unique($names));

    foreach ($workflow['connections'] as $source => $outputs) {
        expect($names)->toContain($source);

        foreach ($outputs['main'] ?? [] as $targets) {
            foreach ($targets as $target) {
                expect($names)->toContain($target['node']);
            }
        }
    }
})->with(n8nWorkflowFiles());

test('the workflow carries no credentials or secrets', function (string $file) {
    $json = (string) file_get_contents($file);
    $workflow = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    foreach ($workflow['nodes'] as $node) {
        expect($node)->not->toHaveKey('credentials', "Node {$node['name']} has a credential reference");
    }

    expect($workflow['pinData'] ?? [])->toBeEmpty();

    $patterns = [
        'DocChat API key' => '/dc_(live|pub|svc)_[A-Za-z0-9]{40}/',
        'webhook secret' => '/whsec_[A-Za-z0-9]{16,}/',
        'Google API key' => '/AIza[0-9A-Za-z_-]{35}/',
        'Google OAuth token' => '/ya29\.[0-9A-Za-z_-]+/',
        'Groq key' => '/gsk_[A-Za-z0-9]{20,}/',
        'Telegram bot token' => '/\d{8,10}:[A-Za-z0-9_-]{35}/',
        'bearer token' => '/Bearer\s+[A-Za-z0-9._-]{20,}/',
        'private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    foreach ($patterns as $what => $pattern) {
        expect(preg_match($pattern, $json))->toBe(0, basename($file)." contains what looks like a {$what}");
    }
})->with(n8nWorkflowFiles());

test('the checker catches a credential reference and a key', function () {
    $leaky = json_encode(['nodes' => [['name' => 'x', 'credentials' => ['httpHeaderAuth' => ['id' => 'abc']]]], 'auth' => 'Bearer dc_live_'.str_repeat('a', 40)]);

    expect(preg_match('/dc_(live|pub|svc)_[A-Za-z0-9]{40}/', (string) $leaky))->toBe(1)
        ->and(json_decode((string) $leaky, true)['nodes'][0])->toHaveKey('credentials');
});
