<?php

namespace App\Services\Calls;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\MessageRole;
use App\Evals\EvalRunner;
use App\Models\Call;
use App\Models\Conversation;
use App\Services\Rag\AnswerService;
use App\Services\Voice\SpokenText;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The voice side of DocChat: what the agent says when a call starts, how it
 * answers each thing the caller says (from the documents, through
 * AnswerService), and when it hangs up. Browser and phone calls share it.
 */
class CallAgent
{
    // The English lines, as constants for code and tests. Other languages
    // and variants live in Phrases.
    public const NOT_HEARD = "Sorry, I didn't catch that. What would you like to know?";

    public const SILENT_GOODBYE = "I can't hear anything, so I'll end the call now. Goodbye.";

    public const GOODBYE = 'Thanks for calling. Bye for now!';

    public const OPTED_OUT = "Understood. You won't get any more calls from us. Goodbye.";

    public const TROUBLE = "Sorry, I'm having trouble answering right now. Please try again in a minute.";

    public const TIME_UP = "We've reached the time limit for this call. Thank you, goodbye.";

    public const MAX_SILENCES = 2;

    public function __construct(private AnswerService $answers, private CurrentWorkspace $current) {}

    /**
     * Start the call: create its transcript and return the first thing to say.
     */
    public function begin(Call $call): string
    {
        $this->current->set($call->workspace);
        $conversation = $this->conversation($call);

        if ($call->status === CallStatus::InProgress && $call->started_at !== null) {
            // Started already (a reload of the call page): say the opening again.
            return $this->opening($call);
        }

        $call->markStarted();
        $opening = $this->opening($call);
        $this->say($conversation, $opening);

        return $opening;
    }

    public function opening(Call $call): string
    {
        $workspace = $call->workspace;

        if ($call->direction === CallDirection::Inbound) {
            return CallSettings::greeting($workspace);
        }

        $profile = VoiceProfile::for($workspace);
        $language = $this->language($call);
        $name = trim(Str::before((string) $call->contact?->name, ' '));

        $line = Phrases::get($language, 'outbound', [
            'first' => $name !== '' ? ' '.$name : '',
            'name' => $profile['assistant_name'],
            'workspace' => $workspace->name,
            'about' => $this->about($call, $language),
        ]);

        $agenda = $call->agendaPoints();

        if ($agenda === []) {
            $line .= ' '.(filled($call->opening) ? trim((string) $call->opening) : Phrases::get($language, 'outbound_default'));

            return $line.' '.Phrases::get($language, 'opt_out_hint');
        }

        // With an agenda the first point is the opening question, after the
        // opt-out hint, so the call ends on something the person can answer.
        if (filled($call->opening)) {
            $line .= ' '.trim((string) $call->opening);
        }

        if ($call->agenda_step < 1) {
            $call->forceFill(['agenda_step' => 1])->save();
        }

        return $line.' '.Phrases::get($language, 'opt_out_hint').' '.$agenda[0];
    }

    /**
     * What to leave on an answering machine (outbound phone calls).
     */
    public function voicemail(Call $call): string
    {
        $profile = VoiceProfile::for($call->workspace);
        $custom = trim((string) $profile['voicemail_message']);
        $language = $this->language($call);

        if ($custom !== '') {
            return Phrases::discloses($custom) ? $custom : Phrases::get($language, 'disclosure').' '.$custom;
        }

        return Phrases::get($language, 'voicemail', [
            'name' => $profile['assistant_name'],
            'workspace' => $call->workspace->name,
            'about' => $this->about($call, $language),
        ]);
    }

    /**
     * A line in the call's language, such as "fillers" or "speech_retry".
     */
    public function phrase(Call $call, string $key, int $turn = 0): string
    {
        return Phrases::get($this->language($call), $key, [], $turn);
    }

    /**
     * Answer what the caller said. $detected is the language speech
     * recognition heard ("en", "ur"), used when auto detection is on.
     */
    public function respond(Call $call, string $heard, ?string $detected = null): CallReply
    {
        $this->current->set($call->workspace);
        $heard = trim(Str::limit($heard, 1000, ''));
        $profile = VoiceProfile::for($call->workspace);

        if ($heard === '') {
            return $this->silence($call);
        }

        if ($profile['auto_language'] && $detected !== null && in_array($detected, Phrases::LANGUAGES, true)) {
            $call->language = $detected;
        }

        $language = $this->language($call);
        $conversation = $this->conversation($call);
        $call->forceFill(['turns' => $call->turns + 1, 'silences' => 0])->save();

        if (Phrases::isOptOut($heard)) {
            $this->hear($conversation, $heard);
            $call->contact?->optOut();
            $text = Phrases::get($language, 'opted_out');
            $this->say($conversation, $text);
            $call->finish(CallStatus::Completed, 'opted_out');

            return new CallReply($text, end: true);
        }

        $agenda = $call->agendaPoints();
        // The last point asked is still open until the caller answers it.
        $agendaOpen = $agenda !== [] && $call->agenda_step <= count($agenda);

        // "No thanks" answers an agenda question; it only ends the call once
        // the agenda is done.
        if (Phrases::isGoodbye($heard) && ! ($agendaOpen && self::isShortNo($heard))) {
            $this->hear($conversation, $heard);
            $text = Phrases::get($language, 'goodbye');
            $this->say($conversation, $text);
            $call->finish(CallStatus::Completed, 'caller_goodbye');

            return new CallReply($text, end: true);
        }

        $style = VoiceProfile::answerStyle($profile, $call->language);

        if ($agendaOpen) {
            $style .= ' This call follows an agenda: do not ask the caller a question yourself, the next question is added after your answer.';
        }

        $message = $this->answers->answer($conversation, $heard, style: $style);
        $max = max(80, (int) config('docchat.calls.max_spoken_chars'));
        $refused = $message->content === AnswerService::NOT_FOUND;

        if ($agendaOpen && $message->error === null) {
            $refused = $refused || EvalRunner::isRefusal($message->content);
            // An agenda call: answer from the documents when they cover what
            // was said, otherwise just acknowledge it. Then ask the next point.
            $next = $call->agenda_step < count($agenda) ? $agenda[$call->agenda_step] : Phrases::get($language, 'anything_else');
            $text = ($refused ? Phrases::get($language, 'agenda_ack', [], $call->turns - 1) : SpokenText::from($message->content, $max, $language)).' '.$next;
            $call->forceFill(['agenda_step' => $call->agenda_step + 1])->save();

            // Keep the transcript to what was said, so summaries see the agenda.
            $message->forceFill(['content' => $refused ? $text : trim($message->content).' '.$next])->save();
            $refused = false;
        } elseif ($message->error !== null) {
            Log::channel('docchat')->warning('call answer failed', ['call_id' => $call->id, 'error' => $message->error]);
            $text = Phrases::get($language, 'trouble');
        } elseif ($refused) {
            $handoff = trim((string) CallSettings::for($call->workspace)['handoff']);
            $text = Phrases::get($language, 'not_found').($handoff !== '' ? ' '.$handoff : '').' '.Phrases::get($language, 'anything_else');
        } else {
            $text = SpokenText::from($message->content, $max, $language);
        }

        if ($call->turns >= max(1, (int) config('docchat.calls.max_turns')) || $call->isOverTime()) {
            $call->finish(CallStatus::Completed, 'limit');

            return new CallReply($text.' '.Phrases::get($language, 'time_up'), end: true, citations: $message->citations ?? [], refused: $refused);
        }

        return new CallReply($text, citations: $message->citations ?? [], refused: $refused);
    }

    /**
     * The caller said nothing: ask again, then hang up.
     */
    public function silence(Call $call): CallReply
    {
        $call->forceFill(['silences' => $call->silences + 1])->save();
        $language = $this->language($call);

        if ($call->silences >= self::MAX_SILENCES) {
            $text = Phrases::get($language, 'silent_goodbye');
            $this->say($this->conversation($call), $text);
            $call->finish(CallStatus::Completed, 'silence');

            return new CallReply($text, end: true);
        }

        return new CallReply(Phrases::get($language, 'not_heard', [], $call->turns));
    }

    public function hangUp(Call $call, string $reason = 'caller_hung_up'): void
    {
        $call->finish(CallStatus::Completed, $reason);
    }

    public static function isGoodbye(string $heard): bool
    {
        return Phrases::isGoodbye($heard);
    }

    /**
     * A short "no" or "no thanks", which answers a yes/no question.
     */
    public static function isShortNo(string $heard): bool
    {
        return (bool) preg_match('/^\s*(no|nope|nah|not really|nein|non|nahi|nahin|नहीं|نہیں|لا)(\W|$)/iu', $heard)
            && count(preg_split('/\s+/u', trim($heard)) ?: []) <= 4;
    }

    public static function isOptOut(string $heard): bool
    {
        return Phrases::isOptOut($heard);
    }

    /**
     * Note what the assistant is about to say on a browser call, so the voice
     * endpoint only speaks the call's own lines (not any text it is sent).
     */
    public static function remember(Call $call, string $text): void
    {
        $key = 'call-spoken:'.$call->id;
        $lines = Cache::get($key, []);
        $lines = array_slice([...(is_array($lines) ? $lines : []), $text], -8);
        Cache::put($key, $lines, now()->addSeconds(Call::maxSeconds() + 600));
    }

    /**
     * May the voice endpoint speak this text on this call? Any part of a
     * recent line, or a filler.
     */
    public function maySpeak(Call $call, string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        foreach (Phrases::LANGUAGES as $language) {
            if (in_array($text, Phrases::all($language, 'fillers'), true)) {
                return true;
            }
        }

        foreach ((array) Cache::get('call-spoken:'.$call->id, []) as $line) {
            if (is_string($line) && str_contains($line, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language of the call's fixed lines.
     */
    public function language(Call $call): string
    {
        return VoiceProfile::phraseLanguage(VoiceProfile::for($call->workspace), $call->language);
    }

    private function about(Call $call, string $language): string
    {
        return filled($call->purpose)
            ? Phrases::get($language, 'about', ['purpose' => rtrim(trim((string) $call->purpose), '.')])
            : '';
    }

    private function conversation(Call $call): Conversation
    {
        if ($call->conversation !== null) {
            return $call->conversation;
        }

        $conversation = Conversation::forChannel($call->workspace, $call->transport->channel(), 'call:'.$call->id, $call->createdBy);
        $conversation->forceFill(['title' => Str::limit($call->direction->label().' call: '.$call->counterpart(), 77)])->save();
        $call->forceFill(['conversation_id' => $conversation->id])->save();
        $call->setRelation('conversation', $conversation);

        return $conversation;
    }

    private function say(Conversation $conversation, string $text): void
    {
        $conversation->messages()->create(['role' => MessageRole::Assistant, 'content' => $text, 'citations' => []]);
        $conversation->touch();
    }

    private function hear(Conversation $conversation, string $text): void
    {
        $conversation->messages()->create(['role' => MessageRole::User, 'content' => $text]);
    }
}
