<?php

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

test('data tables have a workspace_id', function () {
    foreach (['documents', 'chunks', 'conversations', 'messages'] as $table) {
        expect(Schema::hasColumn($table, 'workspace_id'))->toBeTrue("{$table}.workspace_id is missing");
    }
});

test('the migration moves existing rows into a personal workspace per user', function () {
    $migration = require database_path('migrations/2026_11_25_000002_add_workspace_id_to_data_tables.php');
    $migration->down();

    // Rows as they looked before workspaces.
    $userId = DB::table('users')->insertGetId([
        'name' => 'Old User', 'email' => 'old@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $documentId = DB::table('documents')->insertGetId([
        'user_id' => $userId, 'title' => 'Old', 'original_filename' => 'old.txt',
        'storage_path' => 'documents/old.txt', 'mime' => 'text/plain', 'size_bytes' => 10,
        'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('chunks')->insert([
        'document_id' => $documentId, 'page_number' => 1, 'chunk_index' => 0, 'content' => 'x',
        'token_estimate' => 1, 'embedding' => '['.implode(',', array_fill(0, (int) config('docchat.embedding.dimensions'), 0)).']',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $conversationId = DB::table('conversations')->insertGetId([
        'user_id' => $userId, 'title' => 'Old chat', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('messages')->insert([
        'conversation_id' => $conversationId, 'role' => 'user', 'content' => 'hi',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    $user = User::query()->findOrFail($userId);
    $workspace = $user->currentWorkspace();

    expect($workspace?->name)->toBe("Old's workspace")
        ->and($workspace->isOwner($user))->toBeTrue()
        ->and(Document::query()->find($documentId)->workspace_id)->toBe($workspace->id)
        ->and(Chunk::query()->where('document_id', $documentId)->value('workspace_id'))->toBe($workspace->id)
        ->and(Conversation::query()->find($conversationId)->workspace_id)->toBe($workspace->id)
        ->and(Message::query()->where('conversation_id', $conversationId)->value('workspace_id'))->toBe($workspace->id);
});

test('chunks and messages inherit the workspace of their parent', function () {
    $document = Document::factory()->create();
    $chunk = Chunk::factory()->for($document)->create();
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->for($conversation)->create();

    expect($document->workspace_id)->not->toBeNull()
        ->and($chunk->workspace_id)->toBe($document->workspace_id)
        ->and($message->workspace_id)->toBe($conversation->workspace_id);
});

test('a document stays in the workspace when its uploader is deleted', function () {
    $owner = User::factory()->create();
    $uploader = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->members()->attach($uploader->id, ['role' => 'member']);
    $document = Document::factory()->for($uploader)->create(['workspace_id' => $workspace->id]);

    $uploader->delete();

    expect($document->fresh())->not->toBeNull()
        ->and($document->fresh()->user_id)->toBeNull()
        ->and(Workspace::query()->find($workspace->id))->not->toBeNull();
});

test('deleting the only member deletes the workspace, its rows and files', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/'.$workspace->id.'/a.txt',
    ]);
    Storage::disk('local')->put($document->storage_path, 'hello');

    $user->delete();

    expect(Workspace::query()->find($workspace->id))->toBeNull()
        ->and(Document::query()->find($document->id))->toBeNull();
    Storage::disk('local')->assertMissing($document->storage_path);
});
