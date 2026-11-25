<?php

namespace App\Models;

use App\Enums\VoiceCloneStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Voice\PocketTtsVoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A cloned voice, made only from the speaker's own consent recording.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string $speaker_name
 * @property string|null $speaker_email
 * @property string $language Phrases language: en, es, fr, de
 * @property VoiceCloneStatus $status
 * @property string $token_hash
 * @property string $code_words
 * @property string $statement
 * @property string|null $sample_path
 * @property string|null $sample_seconds
 * @property string|null $transcript
 * @property int $attempts
 * @property string|null $failure
 * @property Carbon|null $consented_at
 * @property string|null $consent_ip
 * @property string|null $consent_user_agent
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $createdBy
 */
#[Fillable([
    'workspace_id', 'created_by_user_id', 'name', 'speaker_name', 'speaker_email', 'language', 'status',
    'token_hash', 'code_words', 'statement', 'sample_path', 'sample_seconds', 'transcript', 'attempts',
    'failure', 'consented_at', 'consent_ip', 'consent_user_agent', 'withdrawn_at', 'expires_at',
])]
#[Hidden(['token_hash', 'sample_path'])]
class VoiceClone extends Model
{
    use BelongsToWorkspace;

    public const TOKEN_PREFIX = 'dc_vc_';

    public const MAX_ATTEMPTS = 5;

    protected static function booted(): void
    {
        // The recording and every clip go with the clone.
        static::deleting(function (self $clone): void {
            $clone->deleteAudio();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VoiceCloneStatus::class,
            'attempts' => 'integer',
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * A new consent link token. Only its hash is kept.
     */
    public static function newToken(): string
    {
        return self::TOKEN_PREFIX.Str::random(40);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByToken(string $plain): ?self
    {
        if (! str_starts_with($plain, self::TOKEN_PREFIX) || strlen($plain) > 80) {
            return null;
        }

        return self::query()->withoutGlobalScopes()->with('workspace')->where('token_hash', self::hash($plain))->first();
    }

    public function isReady(): bool
    {
        return $this->status === VoiceCloneStatus::Ready && $this->sample_path !== null;
    }

    public function canRecord(): bool
    {
        return $this->status === VoiceCloneStatus::Waiting && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Delete the recording and stop the clone from being used.
     */
    public function withdraw(): void
    {
        $this->deleteAudio();

        $this->forceFill([
            'status' => VoiceCloneStatus::Withdrawn,
            'sample_path' => null,
            'transcript' => null,
            'withdrawn_at' => now(),
        ])->save();
    }

    public function deleteAudio(): void
    {
        if ($this->sample_path !== null) {
            Storage::disk('local')->delete($this->sample_path);
        }

        PocketTtsVoice::forget($this);
    }

    public function samplePath(): string
    {
        return 'voice-clones/'.$this->workspace_id.'/'.$this->id.'-'.Str::random(16).'.wav';
    }
}
