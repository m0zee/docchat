<?php

namespace App\Models;

use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An API key. Only a SHA-256 hash of the secret is stored; the full key is
 * shown once when it is created.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property ApiKeyType $type
 * @property string $name
 * @property string $prefix
 * @property string $secret_hash
 * @property list<string> $abilities
 * @property list<string>|null $allowed_origins
 * @property int|null $rate_limit_per_minute
 * @property int|null $created_by_user_id
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace|null $workspace
 */
#[Fillable([
    'workspace_id',
    'type',
    'name',
    'prefix',
    'secret_hash',
    'abilities',
    'allowed_origins',
    'rate_limit_per_minute',
    'created_by_user_id',
    'last_used_at',
    'revoked_at',
])]
#[Hidden(['secret_hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApiKeyType::class,
            'abilities' => 'array',
            'allowed_origins' => 'array',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a key and return it with the plain text secret.
     *
     * @param  list<ApiAbility>  $abilities
     * @param  list<string>|null  $allowedOrigins
     * @return array{self, string}
     */
    public static function issue(
        ?Workspace $workspace,
        string $name,
        array $abilities,
        ApiKeyType $type = ApiKeyType::Live,
        ?User $createdBy = null,
        ?array $allowedOrigins = null,
        ?int $rateLimitPerMinute = null,
    ): array {
        $plain = $type->prefix().Str::random(40);

        $key = self::create([
            'workspace_id' => $workspace?->id,
            'type' => $type,
            'name' => Str::limit(trim($name) ?: 'API key', 80, ''),
            'prefix' => substr($plain, 0, strlen($type->prefix()) + 6),
            'secret_hash' => self::hash($plain),
            'abilities' => array_values(array_unique(array_map(fn (ApiAbility $ability): string => $ability->value, $abilities))),
            'allowed_origins' => $allowedOrigins,
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'created_by_user_id' => $createdBy?->id,
        ]);

        return [$key, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByPlain(string $plain): ?self
    {
        if (! preg_match('/^dc_(live|pub|svc)_[A-Za-z0-9]{40}$/', $plain)) {
            return null;
        }

        return self::query()->where('secret_hash', self::hash($plain))->first();
    }

    public function allows(ApiAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities, true);
    }

    /**
     * Whether a browser on $origin may use this key. Public keys list their
     * origins; DocChat's own origin is always allowed for the live preview.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $origin = self::normalizeOrigin($origin);

        if ($origin === null) {
            return false;
        }

        $allowed = array_map(self::normalizeOrigin(...), [...($this->allowed_origins ?? []), (string) config('app.url')]);

        return in_array($origin, $allowed, true);
    }

    /**
     * "https://Example.com/path" becomes "https://example.com". Null for anything that is not http(s).
     */
    public static function normalizeOrigin(?string $value): ?string
    {
        $parts = parse_url(trim((string) $value));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme'].'://'.$parts['host']).$port;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function rateLimit(): int
    {
        $default = $this->type === ApiKeyType::Public
            ? (int) config('docchat.api.public_rate_limit_per_minute')
            : (int) config('docchat.api.rate_limit_per_minute');

        return max(1, $this->rate_limit_per_minute ?? $default);
    }

    /**
     * Record use at most once a minute, to keep writes off the hot path.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
