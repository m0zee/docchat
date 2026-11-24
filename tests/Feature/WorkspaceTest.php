<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

test('the workspace tables exist', function () {
    expect(Schema::hasColumns('workspaces', ['id', 'name', 'slug', 'settings']))->toBeTrue()
        ->and(Schema::hasColumns('workspace_user', ['workspace_id', 'user_id', 'role']))->toBeTrue()
        ->and(Schema::hasColumn('users', 'current_workspace_id'))->toBeTrue();
});

test('a factory user gets a personal workspace they own', function () {
    $user = User::factory()->create(['name' => 'Moiz Hanif']);

    $workspace = $user->currentWorkspace();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($workspace->name)->toBe("Moiz's workspace")
        ->and($user->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('withoutWorkspace skips the personal workspace', function () {
    $user = User::factory()->withoutWorkspace()->create();

    expect($user->workspaces()->count())->toBe(0)
        ->and($user->currentWorkspace())->toBeNull();
});

test('registration creates a personal workspace', function () {
    $this->post(route('register.store'), [
        'name' => 'Ayesha Khan',
        'email' => 'ayesha@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'ayesha@example.com')->firstOrFail();

    expect($user->workspaces()->count())->toBe(1)
        ->and($user->currentWorkspace()?->name)->toBe("Ayesha's workspace");
});

test('slugs are unique', function () {
    $user = User::factory()->withoutWorkspace()->create();

    $a = Workspace::createFor($user, 'Acme');
    $b = Workspace::createFor($user, 'Acme');

    expect($a->slug)->not->toBe($b->slug)
        ->and($a->slug)->toStartWith('acme-');
});

test('a user can switch only to a workspace they belong to', function () {
    $user = User::factory()->create();
    $other = Workspace::factory()->withMember($user)->create();
    $stranger = Workspace::factory()->create();

    expect($user->switchWorkspace($other))->toBeTrue()
        ->and($user->fresh()->currentWorkspace()?->id)->toBe($other->id)
        ->and($user->roleIn($other))->toBe(WorkspaceRole::Member)
        ->and($user->switchWorkspace($stranger))->toBeFalse()
        ->and($user->fresh()->current_workspace_id)->toBe($other->id);
});

test('current workspace falls back when the user left the chosen one', function () {
    $user = User::factory()->create();
    $personal = $user->currentWorkspace();
    $team = Workspace::factory()->withMember($user)->create();
    $user->switchWorkspace($team);

    $team->members()->detach($user->id);

    expect($user->fresh()->currentWorkspace()?->id)->toBe($personal->id);
});

test('owner and member roles are reported', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value]);

    expect($workspace->isOwner($owner))->toBeTrue()
        ->and($workspace->isOwner($member))->toBeFalse()
        ->and($workspace->hasMember($member))->toBeTrue()
        ->and($workspace->hasMember(User::factory()->create()))->toBeFalse();
});
