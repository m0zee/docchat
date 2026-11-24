<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property ConversationChannel $channel
 * @property string $external_id
 * @property string|null $label
 * @property int|null $linked_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $linkedBy
 */
#[Fillable(['workspace_id', 'channel', 'external_id', 'label', 'linked_by_user_id'])]
class ChannelLink extends Model
{
    use BelongsToWorkspace;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['channel' => ConversationChannel::class];
    }

    /**
     * The link for a chat, in any workspace.
     */
    public static function find(ConversationChannel $channel, string $externalId): ?self
    {
        return self::query()->withoutGlobalScopes()
            ->where('channel', $channel->value)
            ->where('external_id', trim($externalId))
            ->first();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
