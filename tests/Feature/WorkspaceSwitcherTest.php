<?php

use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

test('the sidebar shows the current workspace', function () {
    $user = User::factory()->create(['name' => 'Moiz Hanif']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="workspace-switcher"', false)
        ->assertSee('Moiz&#039;s workspace', false);
});

test('switching changes what the pages show', function () {
    $user = User::factory()->create();
    $team = Workspace::factory()->withMember($user)->create(['name' => 'Acme']);
    Document::factory()->for($user)->create(['title' => 'Personal notes']);
    Document::factory()->create(['workspace_id' => $team->id, 'title' => 'Acme handbook']);

    $this->actingAs($user)->get(route('documents.index'))
        ->assertSee('Personal notes')->assertDontSee('Acme handbook');

    Livewire::actingAs($user)->test('workspace-switcher')
        ->call('switchTo', $team->id)
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user->fresh())->get(route('documents.index'))
        ->assertSee('Acme handbook')->assertDontSee('Personal notes');
});

test('a user cannot switch into a workspace they are not in', function () {
    $user = User::factory()->create();
    $personal = $user->currentWorkspace();
    $stranger = Workspace::factory()->create();

    Livewire::actingAs($user)->test('workspace-switcher')->call('switchTo', $stranger->id);

    expect($user->fresh()->current_workspace_id)->toBe($personal->id);
});

test('creating a workspace makes the user its owner and switches to it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('workspace-switcher')
        ->set('newName', 'Client A')
        ->call('create')
        ->assertRedirect(route('dashboard'));

    $workspace = Workspace::query()->where('name', 'Client A')->sole();

    expect($workspace->isOwner($user))->toBeTrue()
        ->and($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('only an owner can rename the workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($workspace);

    Livewire::actingAs($owner)->test('pages::workspace.general')
        ->set('name', 'Renamed')->call('save')->assertHasNoErrors();
    expect($workspace->fresh()->name)->toBe('Renamed');

    Livewire::actingAs($member)->test('pages::workspace.general')
        ->set('name', 'Hijacked')->call('save')->assertForbidden();
    expect($workspace->fresh()->name)->toBe('Renamed');
});

test('web pages never show another workspace\'s data', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $doc = Document::factory()->ready()->for($bob)->create(['title' => 'Bob secret plan']);
    $chat = Conversation::factory()->for($bob)->create(['title' => 'Bob private chat']);

    $this->actingAs($alice);

    $this->get(route('dashboard'))->assertDontSee('Bob secret plan')->assertDontSee('Bob private chat');
    $this->get(route('documents.index'))->assertDontSee('Bob secret plan');
    $this->get(route('chat.index'))->assertDontSee('Bob secret plan')->assertDontSee('Bob private chat');
    $this->get(route('chat.show', $chat))->assertNotFound();

    Livewire::test('pages::documents.index')->call('delete', $doc->id)->assertNotFound();
    expect(Document::withoutGlobalScopes()->find($doc->id))->not->toBeNull();
});
