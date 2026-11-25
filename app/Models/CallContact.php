<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Someone the calling agent may call. Outbound calls need recorded consent
 * and no opt-out.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone E.164, like +923001234567
 * @property string|null $email
 * @property Carbon|null $consent_at
 * @property string|null $consent_note
 * @property Carbon|null $opted_out_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'phone', 'email', 'consent_at', 'consent_note', 'opted_out_at'])]
class CallContact extends Model
{
    use BelongsToWorkspace;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'opted_out_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Call, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class, 'contact_id');
    }

    public function mayBeCalled(): bool
    {
        return $this->consent_at !== null && $this->opted_out_at === null;
    }

    public function optOut(): void
    {
        if ($this->opted_out_at === null) {
            $this->forceFill(['opted_out_at' => now()])->save();
        }
    }

    /**
     * A phone number in E.164 form, or null when it isn't one. Spaces, dashes,
     * dots and brackets are dropped; a leading 00 becomes +.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $phone = preg_replace('/[\s\-().]/', '', trim((string) $phone)) ?? '';

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $phone) ? $phone : null;
    }
}
