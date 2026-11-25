<?php

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Models\Call;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The page an outbound call link opens: who is calling and why, a button to
 * start the call in the browser, and a way to say "don't call me again".
 */
class CallLinkController extends Controller
{
    public function show(string $token): View
    {
        $call = self::usable($token);

        return view('calls.link', [
            'call' => $call,
            'token' => $call === null ? null : $token,
            'workspace' => $call?->workspace,
        ]);
    }

    public function optOut(string $token): RedirectResponse
    {
        $call = self::usable($token);

        if ($call !== null) {
            $call->contact?->optOut();
            $call->finish(CallStatus::Canceled, 'opted_out');
        }

        return redirect()->route('calls.link', $token)->with('opted_out', true);
    }

    private static function usable(string $token): ?Call
    {
        $call = Call::findByToken($token);

        return $call === null
            || $call->transport !== CallTransport::Web
            || $call->direction !== CallDirection::Outbound
            || $call->status->isFinished()
            || $call->isExpired()
            ? null
            : $call->load('workspace', 'contact');
    }
}
