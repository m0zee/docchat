<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\Calls\CallAgent;
use App\Services\Calls\CallReply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Writes the answer to one thing a phone caller said, so the webhook can
 * answer Twilio at once and play a filler and the hold sound meanwhile.
 * The reply waits in the cache under the turn's random key.
 */
class AnswerCallTurnJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $callId, public string $heard, public string $turn) {}

    public function handle(CallAgent $agent): void
    {
        $call = Call::query()->withoutGlobalScopes()->with('workspace')->find($this->callId);

        if ($call === null || $call->status->isFinished()) {
            self::store($this->callId, $this->turn, new CallReply('', end: true));

            return;
        }

        try {
            $reply = $agent->respond($call, $this->heard);
        } catch (Throwable $exception) {
            report($exception);
            $reply = new CallReply($agent->phrase($call, 'trouble'));
        }

        self::store($call->id, $this->turn, $reply);
    }

    public static function key(int $callId, string $turn): string
    {
        return 'call-reply:'.$callId.':'.$turn;
    }

    public static function store(int $callId, string $turn, CallReply $reply): void
    {
        Cache::put(self::key($callId, $turn), ['text' => $reply->text, 'end' => $reply->end], now()->addMinutes(10));
    }

    /**
     * @return array{text: string, end: bool}|null
     */
    public static function result(int $callId, string $turn): ?array
    {
        $value = Cache::get(self::key($callId, $turn));

        return is_array($value) ? ['text' => (string) $value['text'], 'end' => (bool) $value['end']] : null;
    }
}
