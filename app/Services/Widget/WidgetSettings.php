<?php

namespace App\Services\Widget;

use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;

/**
 * The website widget's look and behaviour, kept in workspaces.settings.widget.
 * The widget's public key (dc_pub_) is not a secret: it sits in the client's
 * page. It is stored here so the embed snippet can be shown again; the key
 * itself only allows asking, from the listed origins, at a per-visitor limit.
 */
class WidgetSettings
{
    public const DEFAULTS = [
        'title' => 'Ask our documents',
        'welcome' => 'Hi! Ask me anything about our documents.',
        'color' => '#4f46e5',
        'position' => 'right',
        'handoff_email' => null,
        'handoff_text' => 'Need a person? Email us.',
    ];

    /**
     * @return array{title: string, welcome: string, color: string, position: string, handoff_email: string|null, handoff_text: string}
     */
    public static function for(Workspace $workspace): array
    {
        $saved = $workspace->setting('widget', []);
        $settings = array_merge(self::DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS));

        /** @var array{title: string, welcome: string, color: string, position: string, handoff_email: string|null, handoff_text: string} $settings */
        return $settings;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(Workspace $workspace, array $values): void
    {
        $settings = $workspace->settings ?? [];
        $settings['widget'] = array_merge(
            is_array($settings['widget'] ?? null) ? $settings['widget'] : [],
            array_intersect_key($values, self::DEFAULTS),
        );

        $workspace->update(['settings' => $settings]);
    }

    public static function key(Workspace $workspace): ?ApiKey
    {
        $id = $workspace->setting('widget.key_id');

        return ! is_int($id) ? null : ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', ApiKeyType::Public->value)
            ->active()
            ->find($id);
    }

    public static function plainKey(Workspace $workspace): ?string
    {
        return self::key($workspace) === null ? null : $workspace->setting('widget.public_key');
    }

    /**
     * Revoke the current widget key, if any, and make a new one.
     *
     * @param  list<string>  $origins
     */
    public static function rotateKey(Workspace $workspace, array $origins, ?User $by = null): string
    {
        self::key($workspace)?->revoke();

        [$key, $plain] = ApiKey::issue($workspace, 'Website widget', [ApiAbility::Ask], ApiKeyType::Public, $by, $origins);

        $settings = $workspace->settings ?? [];
        $settings['widget'] = array_merge(is_array($settings['widget'] ?? null) ? $settings['widget'] : [], [
            'key_id' => $key->id,
            'public_key' => $plain,
        ]);
        $workspace->update(['settings' => $settings]);

        return $plain;
    }

    /**
     * Turns lines like "example.com" or "https://shop.example.com/page" into
     * origins. Bare domains get https.
     *
     * @return array{list<string>, list<string>} [origins, invalid lines]
     */
    public static function parseOrigins(string $lines): array
    {
        $origins = [];
        $invalid = [];

        foreach (preg_split('/[\s,]+/', trim($lines)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $origin = ApiKey::normalizeOrigin(str_contains($line, '://') ? $line : 'https://'.$line);

            $host = (string) parse_url((string) $origin, PHP_URL_HOST);

            if ($origin === null || str_contains($origin, '*') || ($host !== 'localhost' && ! str_contains($host, '.'))) {
                $invalid[] = $line;
            } else {
                $origins[] = $origin;
            }
        }

        return [array_values(array_unique($origins)), $invalid];
    }
}
