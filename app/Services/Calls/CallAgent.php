<?php

namespace App\Services\Calls;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\MessageRole;
use App\Models\Call;
use App\Models\Conversation;
use App\Services\Rag\AnswerService;
use App\Services\Voice\SpokenText;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The voice side of DocChat: what the agent says when a call starts, how it
 * answers each thing the caller says (from the documents, through
 * AnswerService), and when it hangs up. Browser and phone calls share it.
 */
class CallAgent
{
    public const NOT_HEARD = "Sorry, I didn't catch that. What would you like to know?";

    public const SILENT_GOODBYE = "I can't hear anything, so I'll end the call now. Goodbye.";

    public const GOODBYE = 'Thank you for calling. Goodbye!';

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

        $name = trim(Str::before((string) $call->contact?->name, ' '));
        $line = 'Hi'.($name !== '' ? ' '.$name : '').', this is an automated assistant calling for '.$workspace->name;
        $line .= filled($call->purpose) ? ' about '.rtrim((string) $call->purpose, '.').'.' : '.';
        $line .= ' '.(filled($call->opening)
            ? trim((string) $call->opening)
            : 'I can answer your questions from our documents. What would you like to know?');

        return $line.' If you would rather not get these calls, just say stop calling.';
    }

    /**
     * Answer what the caller said.
     */
    public function respond(Call $call, string $heard): CallReply
    {
        $this->current->set($call->workspace);
        $heard = trim(Str::limit($heard, 1000, ''));

        if ($heard === '') {
            return $this->silence($call);
        }

        $conversation = $this->conversation($call);
        $call->forceFill(['turns' => $call->turns + 1, 'silences' => 0])->save();

        if (self::isOptOut($heard)) {
            $this->hear($conversation, $heard);
            $call->contact?->optOut();
            $this->say($conversation, self::OPTED_OUT);
            $call->finish(CallStatus::Completed, 'opted_out');

            return new CallReply(self::OPTED_OUT, end: true);
        }

        if (self::isGoodbye($heard)) {
            $this->hear($conversation, $heard);
            $this->say($conversation, self::GOODBYE);
            $call->finish(CallStatus::Completed, 'caller_goodbye');

            return new CallReply(self::GOODBYE, end: true);
        }

        $message = $this->answers->answer($conversation, $heard);
        $max = max(80, (int) config('docchat.calls.max_spoken_chars'));
        $refused = $message->content === AnswerService::NOT_FOUND;

        if ($message->error !== null) {
            Log::channel('docchat')->warning('call answer failed', ['call_id' => $call->id, 'error' => $message->error]);
            $text = self::TROUBLE;
        } elseif ($refused) {
            $handoff = trim((string) CallSettings::for($call->workspace)['handoff']);
            $text = "I couldn't find that in our documents.".($handoff !== '' ? ' '.$handoff : '').' Is there anything else I can help with?';
        } else {
            $text = SpokenText::from($message->content, $max);
        }

        if ($call->turns >= max(1, (int) config('docchat.calls.max_turns')) || $call->isOverTime()) {
            $call->finish(CallStatus::Completed, 'limit');

            return new CallReply($text.' '.self::TIME_UP, end: true, citations: $message->citations ?? [], refused: $refused);
        }

        return new CallReply($text, citations: $message->citations ?? [], refused: $refused);
    }

    /**
     * The caller said nothing: ask again, then hang up.
     */
    public function silence(Call $call): CallReply
    {
        $call->forceFill(['silences' => $call->silences + 1])->save();

        if ($call->silences >= self::MAX_SILENCES) {
            $this->say($this->conversation($call), self::SILENT_GOODBYE);
            $call->finish(CallStatus::Completed, 'silence');

            return new CallReply(self::SILENT_GOODBYE, end: true);
        }

        return new CallReply(self::NOT_HEARD);
    }

    public function hangUp(Call $call, string $reason = 'caller_hung_up'): void
    {
        $call->finish(CallStatus::Completed, $reason);
    }

    public static function isGoodbye(string $heard): bool
    {
        $words = preg_split('/\s+/u', trim($heard)) ?: [];

        return count($words) <= 7 && (bool) preg_match(
            "/\b(bye|good ?bye|that'?s all|that is all|nothing else|no,? thanks?|no,? thank you|hang up|end (the )?call)\b/iu",
            $heard,
        );
    }

    public static function isOptOut(string $heard): bool
    {
        return (bool) preg_match(
            "/\b(stop calling|don'?t call|do not call|never call|remove me|unsubscribe|opt (me )?out)\b/iu",
            $heard,
        );
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
