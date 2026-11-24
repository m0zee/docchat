<?php

use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
});

test('members page is for owners only', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    $this->actingAs($this->owner)->get(route('workspace.members'))->assertOk()->assertSee($this->owner->email);
    $this->actingAs($member)->get(route('workspace.members'))->assertForbidden();
});

test('an owner adds an existing user directly', function () {
    $colleague = User::factory()->create(['email' => 'colleague@example.com']);

    Livewire::actingAs($this->owner)->test('pages::workspace.members')
        ->set('email', 'Colleague@Example.com')
        ->set('role', 'member')
        ->call('add')
        ->assertHasNoErrors();

    expect($this->workspace->roleOf($colleague))->toBe(WorkspaceRole::Member);
});

test('adding an unknown email asks for an invite instead', function () {
    Livewire::actingAs($this->owner)->test('pages::workspace.members')
        ->set('email', 'nobody@example.com')
        ->call('add')
        ->assertHasErrors('email');
});

test('an owner sends an invite link by email and sees it once', function () {
    Mail::fake();

    $component = Livewire::actingAs($this->owner)->test('pages::workspace.members')
        ->set('email', 'new@example.com')
        ->set('role', 'owner')
        ->call('invite')
        ->assertHasNoErrors();

    $url = $component->get('inviteUrl');
    $invitation = WorkspaceInvitation::query()->sole();

    expect($url)->toStartWith(url('invitations').'/')
        ->and($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Owner)
        ->and($invitation->token_hash)->toBe(hash('sha256', basename($url)));

    Mail::assertQueued(WorkspaceInvitationMail::class, fn ($mail) => $mail->hasTo('new@example.com') && $mail->url === $url);
});

test('the invitation mail renders with the link', function () {
    $this->workspace->update(['name' => 'Acme Support']);
    [$invitation, $token] = WorkspaceInvitation::issue($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);
    $mail = new WorkspaceInvitationMail($invitation, route('invitations.show', $token));

    $mail->assertSeeInHtml(route('invitations.show', $token))->assertSeeInHtml($this->workspace->name);
});

test('a new user registers from the link and joins', function () {
    [, $token] = WorkspaceInvitation::issue($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);

    $this->get(route('invitations.show', $token))->assertOk()->assertSee('Create an account');

    $this->post(route('register.store'), [
        'name' => 'New Person', 'email' => 'new@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', $token));

    $user = User::query()->where('email', 'new@example.com')->sole();
    $user->markEmailAsVerified();

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    expect($this->workspace->roleOf($user))->toBe(WorkspaceRole::Member)
        ->and($user->fresh()->current_workspace_id)->toBe($this->workspace->id);
});

test('an invitation works once, expires, and only for its email', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $other = User::factory()->create();

    [, $token] = WorkspaceInvitation::issue($this->workspace, 'invitee@example.com', WorkspaceRole::Member, $this->owner);

    $this->actingAs($other)->post(route('invitations.accept', $token))->assertRedirect(route('invitations.show', $token));
    expect($this->workspace->hasMember($other))->toBeFalse();

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
    expect($this->workspace->hasMember($invitee))->toBeTrue();

    $this->get(route('invitations.show', $token))->assertSee('Invitation not valid');

    [, $expired] = WorkspaceInvitation::issue($this->workspace, 'late@example.com', WorkspaceRole::Member, $this->owner);
    $this->travel(WorkspaceInvitation::VALID_DAYS + 1)->days();
    $this->get(route('invitations.show', $expired))->assertSee('Invitation not valid');
});

test('a new invite replaces the old open one', function () {
    [, $first] = WorkspaceInvitation::issue($this->workspace, 'x@example.com', WorkspaceRole::Member, $this->owner);
    [, $second] = WorkspaceInvitation::issue($this->workspace, 'x@example.com', WorkspaceRole::Member, $this->owner);

    expect(WorkspaceInvitation::findOpen($first))->toBeNull()
        ->and(WorkspaceInvitation::findOpen($second))->not->toBeNull();
});

test('roles change and members are removed, but never the last owner', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($this->owner)->test('pages::workspace.members')
        ->call('changeRole', $this->owner->id, 'member')
        ->call('remove', $this->owner->id);
    expect($this->workspace->isOwner($this->owner))->toBeTrue();

    Livewire::actingAs($this->owner)->test('pages::workspace.members')
        ->call('changeRole', $member->id, 'owner');
    expect($this->workspace->isOwner($member))->toBeTrue();

    Livewire::actingAs($this->owner)->test('pages::workspace.members')
        ->call('remove', $member->id);
    expect($this->workspace->hasMember($member))->toBeFalse();
});

test('a member can leave and lands in a workspace of their own', function () {
    $member = User::factory()->withoutWorkspace()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    Livewire::actingAs($member)->test('pages::workspace.general')->call('leave');

    expect($this->workspace->hasMember($member))->toBeFalse()
        ->and($member->fresh()->currentWorkspace())->not->toBeNull()
        ->and($member->fresh()->currentWorkspace()->id)->not->toBe($this->workspace->id);
});

test('the only owner cannot leave', function () {
    Livewire::actingAs($this->owner)->test('pages::workspace.general')->call('leave');

    expect($this->workspace->hasMember($this->owner))->toBeTrue();
});

test('other workspaces\' invitations cannot be revoked', function () {
    $other = Workspace::factory()->create();
    [$invitation] = WorkspaceInvitation::issue($other, 'z@example.com', WorkspaceRole::Member, null);

    Livewire::actingAs($this->owner)->test('pages::workspace.members')->call('revokeInvitation', $invitation->id);

    expect($invitation->fresh())->not->toBeNull();
});
