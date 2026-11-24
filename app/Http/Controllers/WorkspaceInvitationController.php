<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceInvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = WorkspaceInvitation::findOpen($token);

        if ($invitation !== null && ! $request->user() instanceof User) {
            // Log in or register, then come back to this page.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
            'user' => $request->user(),
        ]);
    }

    public function accept(Request $request, string $token, CurrentWorkspace $current): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $invitation = WorkspaceInvitation::findOpen($token);

        if ($invitation === null) {
            return redirect()->route('invitations.show', $token);
        }

        if (! $invitation->accept($user)) {
            return redirect()->route('invitations.show', $token)
                ->with('invitation_error', __('This invitation is for :email. Log in with that address to accept it.', ['email' => $invitation->email]));
        }

        $current->forget();

        return redirect()->route('dashboard')->with('status', __('You joined :workspace.', ['workspace' => $invitation->workspace->name]));
    }
}
