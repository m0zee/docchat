<?php

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Documents\DocumentService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('the global scope limits every workspace model to the current workspace', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    $myDocument = Document::factory()->create(['workspace_id' => $mine->id]);
    $theirDocument = Document::factory()->create(['workspace_id' => $theirs->id]);
    Chunk::factory()->for($myDocument)->create();
    Chunk::factory()->for($theirDocument)->create();
    Conversation::factory()->create(['workspace_id' => $theirs->id]);

    app(CurrentWorkspace::class)->set($mine);

    expect(Document::query()->pluck('id')->all())->toBe([$myDocument->id])
        ->and(Document::query()->find($theirDocument->id))->toBeNull()
        ->and(Chunk::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(0);
});

test('without a current workspace nothing is scoped (jobs and commands pass one explicitly)', function () {
    Document::factory()->count(2)->create();

    expect(Document::query()->count())->toBe(2);
});

test('new rows go into the current workspace', function () {
    $workspace = Workspace::factory()->create();
    app(CurrentWorkspace::class)->set($workspace);

    $conversation = Conversation::query()->create(['title' => 'API chat']);

    expect($conversation->workspace_id)->toBe($workspace->id)
        ->and($conversation->user_id)->toBeNull();
});

test('the web current workspace follows the signed-in user and their switch', function () {
    $user = User::factory()->create();
    $team = Workspace::factory()->withMember($user)->create();
    $this->actingAs($user);
    $current = app(CurrentWorkspace::class);

    expect($current->id())->toBe($user->currentWorkspace()->id);

    $user->switchWorkspace($team);

    expect($current->id())->toBe($team->id);
});

test('members of a shared workspace see its documents, outsiders do not', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($workspace);
    $document = Document::factory()->for($owner)->create(['title' => 'Shared handbook']);

    expect($member->can('view', $document))->toBeTrue()
        ->and($member->can('delete', $document))->toBeTrue()
        ->and($outsider->can('view', $document))->toBeFalse();

    $this->actingAs($member)->get(route('documents.index'))->assertSee('Shared handbook');
    $this->actingAs($outsider)->get(route('documents.index'))->assertDontSee('Shared handbook');
});

test('only owners can manage a workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->members()->attach($member->id, ['role' => 'member']);

    expect($owner->can('manage', $workspace))->toBeTrue()
        ->and($member->can('manage', $workspace))->toBeFalse()
        ->and($member->can('view', $workspace))->toBeTrue();
});

test('uploads are stored under the workspace folder', function () {
    Storage::fake('local');
    Queue::fake();
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();

    $document = app(DocumentService::class)->createFromUpload(
        UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        $workspace,
        $user,
    );

    expect($document->workspace_id)->toBe($workspace->id)
        ->and($document->storage_path)->toStartWith('documents/'.$workspace->id.'/');
});
