<?php

namespace App\Http\Controllers\Twilio;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Http\Controllers\Controller;
use App\Jobs\AnswerCallTurnJob;
use App\Models\Call;
use App\Models\CallContact;
use App\Services\Calls\CallAgent;
use App\Services\Calls\CallSettings;
use App\Services\Calls\Phrases;
use App\Services\Calls\VoiceProfile;
use App\Services\Twilio\TwilioSignature;
use App\Services\Twilio\TwiML;
use App\Support\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

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
            return TwiML::response('<Say>Sorry, this number is not connected to a DocChat workspace yet. Goodbye.</Say><Hangup/>');
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

        return TwiML::for($workspace)->ask($this->agent->begin($call), $this->gatherUrl($call));
    }

    /**
     * An outbound call was picked up. With voicemail detection on, Twilio
     * says who answered (AnsweredBy): a machine gets a short message or a
     * hang-up instead of the conversation.
     */
    public function answer(Request $request, int $call): Response
    {
        $model = $this->find($call, $request);

        if ($model === null || $model->status->isFinished()) {
            return TwiML::response('<Hangup/>');
        }

        $twiml = TwiML::for($model->workspace);
        $answeredBy = (string) $request->input('AnsweredBy', '');

        if ($answeredBy !== '') {
            $model->forceFill(['answered_by' => substr($answeredBy, 0, 30)])->save();
        }

        if (str_starts_with($answeredBy, 'machine') || $answeredBy === 'fax') {
            $mode = VoiceProfile::for($model->workspace)['voicemail'];
            $message = $mode === 'leave_message' && $answeredBy !== 'fax' ? $this->agent->voicemail($model) : '';
            $model->finish(CallStatus::Completed, $message === '' ? 'voicemail_hung_up' : 'voicemail_left');

            return $twiml->hangUp($message);
        }

        return $twiml->ask($this->agent->begin($model), $this->gatherUrl($model));
    }

    /**
     * What the caller said (SpeechResult), or nothing after a pause.
     */
    public function gather(Request $request, int $call): Response
    {
        $model = $this->find($call, $request);

        if ($model === null || $model->status->isFinished()) {
            return TwiML::response('<Hangup/>');
        }

        if ($model->status !== CallStatus::InProgress) {
            $model->markStarted();
        }

        $heard = trim((string) $request->input('SpeechResult', ''));
        $twiml = TwiML::for($model->workspace);

        // Silence, goodbye and "stop calling" need no model: answer at once.
        if ($heard === '' || Phrases::isOptOut($heard) || Phrases::isGoodbye($heard)) {
            $reply = $this->agent->respond($model, $heard);

            return $reply->end ? $twiml->hangUp($reply->text) : $twiml->ask($reply->text, $this->gatherUrl($model));
        }

        // The answer is written in a job. If it isn't ready yet, the caller
        // hears a short filler and the hold sound until it is.
        $turn = Str::random(16);
        AnswerCallTurnJob::dispatch($model->id, $heard, $turn);

        return $this->replyOrWait($model, $twiml, $turn, 0);
    }

    /**
     * Twilio comes back here while the answer is being written.
     */
    public function reply(Request $request, int $call, string $turn): Response
    {
        $model = $this->find($call, $request);

        if ($model === null) {
            return TwiML::response('<Hangup/>');
        }

        return $this->replyOrWait($model, TwiML::for($model->workspace), $turn, max(1, (int) $request->query('attempt', '1')));
    }

    private function replyOrWait(Call $call, TwiML $twiml, string $turn, int $attempt): Response
    {
        $result = AnswerCallTurnJob::result($call->id, $turn);

        if ($result !== null) {
            if ($result['end']) {
                return $twiml->hangUp($result['text']);
            }

            return $twiml->ask($result['text'], $this->gatherUrl($call));
        }

        if ($call->status->isFinished()) {
            return $twiml->hangUp();
        }

        // Each wait is about three seconds (a filler or the hold sound).
        if ($attempt * 3 >= max(3, (int) config('docchat.calls.phone_answer_wait_seconds'))) {
            return $twiml->ask($this->agent->phrase($call, 'trouble'), $this->gatherUrl($call));
        }

        $filler = $attempt === 0 && VoiceProfile::for($call->workspace)['fillers']
            ? $this->agent->phrase($call, 'fillers', $call->turns)
            : '';

        return $twiml->wait($filler, TwilioSignature::url('twilio/voice/calls/'.$call->id.'/reply/'.$turn.'?attempt='.($attempt + 1)));
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
