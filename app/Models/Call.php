<?php

namespace App\Models;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One voice call with the calling agent, in the browser or on the phone.
 * The transcript is the conversation's messages.
 *
 * @property int $id
 * @property int|null $conversation_id
 * @property int|null $contact_id
 * @property int|null $created_by_user_id
 * @property CallDirection $direction
 * @property CallTransport $transport
 * @property CallStatus $status
 * @property string|null $purpose
 * @property string|null $opening
 * @property string|null $token_hash
 * @property string|null $provider_sid
 * @property string|null $from_number
 * @property string|null $to_number
 * @property int $turns
 * @property int $silences
 * @property string|null $end_reason
 * @property string|null $language
 * @property string|null $answered_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation|null $conversation
 * @property-read CallContact|null $contact
 * @property-read User|null $createdBy
 */
#[Fillable([
    'workspace_id',
    'conversation_id',
    'contact_id',
    'created_by_user_id',
    'direction',
    'transport',
    'status',
    'purpose',
    'opening',
    'token_hash',
    'provider_sid',
    'from_number',
    'to_number',
    'turns',
    'silences',
    'end_reason',
    'language',
    'answered_by',
    'expires_at',
    'started_at',
    'ended_at',
    'duration_seconds',
])]
#[Hidden(['token_hash'])]
class Call extends Model
{
    use BelongsToWorkspace;

    public const TOKEN_PREFIX = 'dc_call_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'transport' => CallTransport::class,
            'status' => CallStatus::class,
            'turns' => 'integer',
            'silences' => 'integer',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<CallContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CallContact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Give the call a new browser token and return it. Only a hash is kept.
     */
    public function issueToken(): string
    {
        $plain = self::TOKEN_PREFIX.Str::random(40);
        $this->forceFill(['token_hash' => self::hash($plain)])->save();

        return $plain;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * The call for a browser token, in any workspace.
     */
    public static function findByToken(string $plain): ?self
    {
        if (! str_starts_with($plain, self::TOKEN_PREFIX) || strlen($plain) > 80) {
            return null;
        }

        return self::query()->withoutGlobalScopes()->where('token_hash', self::hash($plain))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Longest call, in seconds, from config. Twilio trial calls stop at 10 minutes anyway.
     */
    public static function maxSeconds(): int
    {
        return max(30, (int) config('docchat.calls.max_minutes') * 60);
    }

    public function isOverTime(): bool
    {
        return $this->started_at !== null && $this->started_at->diffInSeconds(now()) >= self::maxSeconds();
    }

    public function markStarted(): void
    {
        $this->forceFill([
            'status' => CallStatus::InProgress,
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    public function finish(CallStatus $status, string $reason, ?int $durationSeconds = null): void
    {
        if ($this->status->isFinished()) {
            // Twilio's final status can arrive after we hung up: keep the
            // first reason, but take its exact duration.
            if ($durationSeconds !== null) {
                $this->forceFill(['duration_seconds' => $durationSeconds])->save();
            }

            return;
        }

        $ended = now();

        $this->forceFill([
            'status' => $status,
            'end_reason' => Str::limit($reason, 40, ''),
            'ended_at' => $ended,
            'duration_seconds' => $durationSeconds
                ?? ($this->started_at === null ? 0 : (int) $this->started_at->diffInSeconds($ended)),
        ])->save();
    }

    public function counterpart(): string
    {
        return $this->contact->name
            ?? ($this->direction === CallDirection::Inbound ? $this->from_number : $this->to_number)
            ?? ($this->transport === CallTransport::Web ? 'Website visitor' : 'Unknown');
    }
}
