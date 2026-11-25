<?php

namespace App\Http\Controllers\Twilio;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallContact;
use App\Services\Calls\CallAgent;
use App\Services\Calls\CallSettings;
use App\Services\Twilio\TwilioSignature;
use App\Services\Twilio\TwiML;
use App\Support\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The phone line's webhooks. Twilio posts form fields (CallSid, From, To,
 * SpeechResult, CallStatus) and runs the TwiML we answer with.
 */
class TwilioVoiceController extends Controller
{
    public function __construct(private CallAgent $agent, private CurrentWorkspace $current) {}

    /**
     * Someone called the workspace's Twilio number.
     */
    public function inbound(Request $request): Response
    {
        $sid = (string) $request->input('CallSid');
        $workspace = CallSettings::workspaceForNumber((string) $request->input('To'));

        if ($workspace === null || $sid === '') {
            return TwiML::hangUp('Sorry, this number is not connected to a DocChat workspace yet. Goodbye.');
        }

        $this->current->set($workspace);
        $from = CallContact::normalizePhone((string) $request->input('From'));

        $find = fn (): ?Call => Call::query()->withoutGlobalScopes()->where('provider_sid', $sid)->first();

        try {
            $call = $find() ?? Call::query()->create([
                'workspace_id' => $workspace->id,
                'contact_id' => $from === null ? null : CallContact::query()->inWorkspace($workspace)->where('phone', $from)->value('id'),
                'direction' => CallDirection::Inbound,
                'transport' => CallTransport::Phone,
                'status' => CallStatus::Ringing,
                'provider_sid' => $sid,
                'from_number' => $from,
                'to_number' => CallContact::normalizePhone((string) $request->input('To')),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Twilio retried while the first request was still running.
            $call = $find() ?? abort(500);
        }

        return TwiML::ask($this->agent->begin($call), $this->gatherUrl($call));
    }

    /**
     * An outbound call was picked up.
     */
    public function answer(Request $request, int $call): Response
    {
        $model = $this->find($call, $request);

        if ($model === null || $model->status->isFinished()) {
            return TwiML::hangUp();
        }

        return TwiML::ask($this->agent->begin($model), $this->gatherUrl($model));
    }

    /**
     * What the caller said (SpeechResult), or nothing after a pause.
     */
    public function gather(Request $request, int $call): Response
    {
        $model = $this->find($call, $request);

        if ($model === null || $model->status->isFinished()) {
            return TwiML::hangUp();
        }

        if ($model->status !== CallStatus::InProgress) {
            $model->markStarted();
        }

        $reply = $this->agent->respond($model, (string) $request->input('SpeechResult', ''));

        return $reply->end
            ? TwiML::hangUp($reply->text)
            : TwiML::ask($reply->text, $this->gatherUrl($model));
    }

    /**
     * Twilio's status callback: ringing, answered, and the final status with
     * CallDuration.
     */
    public function status(Request $request, int $call): Response
    {
        $model = $this->find($call, $request);
        $status = CallStatus::fromTwilio((string) $request->input('CallStatus'));

        if ($model !== null && $status !== null) {
            if ($status->isFinished()) {
                $duration = $request->input('CallDuration');
                $model->finish($status, 'twilio_'.$status->value, is_numeric($duration) ? (int) $duration : null);
            } elseif (! $model->status->isFinished() && $model->status !== CallStatus::InProgress) {
                $model->forceFill(['status' => $status])->save();
            }
        }

        return new Response('', 204);
    }

    private function find(int $id, Request $request): ?Call
    {
        $call = Call::query()->withoutGlobalScopes()->with('workspace')->find($id);

        // The sid must match, so a signed request for one call can't steer another.
        if ($call === null || $call->transport !== CallTransport::Phone
            || ($call->provider_sid !== null && $call->provider_sid !== (string) $request->input('CallSid'))) {
            return null;
        }

        if ($call->provider_sid === null && filled($request->input('CallSid'))) {
            $call->forceFill(['provider_sid' => (string) $request->input('CallSid')])->save();
        }

        $this->current->set($call->workspace);

        return $call;
    }

    private function gatherUrl(Call $call): string
    {
        return TwilioSignature::url('twilio/voice/calls/'.$call->id.'/gather');
    }
}
