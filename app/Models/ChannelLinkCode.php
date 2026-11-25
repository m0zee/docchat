<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A one-time code that links a chat to a workspace. Only a SHA-256 hash is
 * stored; the code is shown once to the owner, who sends it to the bot.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property ConversationChannel $channel
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'user_id', 'channel', 'code_hash', 'expires_at', 'used_at'])]
class ChannelLinkCode extends Model
{
    public const VALID_MINUTES = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Create a code and return it with the plain text. Telegram's /start
     * parameter allows only letters, digits, _ and -, up to 64 characters.
     *
     * @return array{self, string}
     */
    public static function issue(Workspace $workspace, ConversationChannel $channel, ?User $user): array
    {
        $plain = Str::random(24);

        $code = self::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'channel' => $channel,
            'code_hash' => self::hash($plain),
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        return [$code, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', trim($plain));
    }

    /**
     * Use a code to link a chat. Returns null for an unknown, used or
     * expired code. A chat linked before moves to the code's workspace.
     */
    public static function redeem(string $plain, ConversationChannel $channel, string $externalId, ?string $label = null): ?ChannelLink
    {
        if (! preg_match('/^[A-Za-z0-9]{24}$/', trim($plain))) {
            return null;
        }

        return DB::transaction(function () use ($plain, $channel, $externalId, $label): ?ChannelLink {
            $code = self::query()
                ->where('code_hash', self::hash($plain))
                ->where('channel', $channel->value)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($code === null) {
                return null;
            }

            $code->update(['used_at' => now()]);

            return ChannelLink::query()->withoutGlobalScopes()->updateOrCreate(
                ['channel' => $channel->value, 'external_id' => trim($externalId)],
                ['workspace_id' => $code->workspace_id, 'label' => $label === null ? null : Str::limit($label, 250), 'linked_by_user_id' => $code->user_id],
            );
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
