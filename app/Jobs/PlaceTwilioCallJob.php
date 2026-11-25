<?php

namespace App\Jobs;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Services\Calls\CallingHours;
use App\Services\Twilio\TwilioClient;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts an outbound phone call. Checks consent, opt-out and calling hours
 * again at dial time, because the job can run later than it was queued.
 */
class PlaceTwilioCallJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $callId) {}

    public function handle(TwilioClient $twilio, CurrentWorkspace $current): void
    {
        $call = Call::query()->withoutGlobalScopes()->with(['workspace', 'contact'])->find($this->callId);

        if ($call === null || $call->status !== CallStatus::Queued) {
            return;
        }

        $current->set($call->workspace);
        $contact = $call->contact;

        if ($contact === null || $contact->phone === null) {
            $call->finish(CallStatus::Canceled, 'no_phone_number');

            return;
        }

        $reason = match (true) {
            ! $contact->mayBeCalled() => 'no_consent',
            ! CallingHours::allows() => 'outside_calling_hours',
            default => null,
        };

        if ($reason !== null) {
            $call->finish(CallStatus::Canceled, $reason);

            return;
        }

        try {
            $sid = $twilio->placeCall($call, $contact->phone);
        } catch (Throwable $exception) {
            Log::channel('docchat')->warning('twilio call failed', ['call_id' => $call->id, 'error' => $exception->getMessage()]);
            $call->finish(CallStatus::Failed, 'twilio_refused');

            return;
        }

        $call->forceFill([
            'provider_sid' => $sid,
            'status' => CallStatus::Ringing,
            'from_number' => config('docchat.calls.twilio.from_number'),
            'to_number' => $contact->phone,
        ])->save();
    }
}
