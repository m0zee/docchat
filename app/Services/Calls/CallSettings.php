<?php

namespace App\Services\Calls;

use App\Models\CallContact;
use App\Models\Workspace;

/**
 * The calling agent's settings, kept in workspaces.settings.calls.
 */
class CallSettings
{
    public const DEFAULTS = [
        // Lets website visitors call from the widget.
        'widget_calls' => true,
        'greeting' => null,
        'handoff' => null,
        // The Twilio number that rings this workspace, in E.164.
        'phone_number' => null,
    ];

    /**
     * @return array{widget_calls: bool, greeting: string|null, handoff: string|null, phone_number: string|null}
     */
    public static function for(Workspace $workspace): array
    {
        $saved = $workspace->setting('calls', []);
        $settings = array_merge(self::DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS));
        $settings['widget_calls'] = (bool) $settings['widget_calls'];

        /** @var array{widget_calls: bool, greeting: string|null, handoff: string|null, phone_number: string|null} $settings */
        return $settings;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(Workspace $workspace, array $values): void
    {
        $settings = $workspace->settings ?? [];
        $settings['calls'] = array_merge(
            is_array($settings['calls'] ?? null) ? $settings['calls'] : [],
            array_intersect_key($values, self::DEFAULTS),
        );

        $workspace->update(['settings' => $settings]);
    }

    public static function defaultGreeting(Workspace $workspace): string
    {
        return 'Hi, you have reached '.$workspace->name.'. I am an automated assistant, and I answer from '
            .$workspace->name.'\'s documents. How can I help you?';
    }

    public static function greeting(Workspace $workspace): string
    {
        $greeting = trim((string) self::for($workspace)['greeting']);

        return $greeting !== '' ? $greeting : self::defaultGreeting($workspace);
    }

    /**
     * The workspace a Twilio number rings, if one claimed it.
     */
    public static function workspaceForNumber(string $number): ?Workspace
    {
        $number = CallContact::normalizePhone($number);

        return $number === null ? null : Workspace::query()->where('settings->calls->phone_number', $number)->first();
    }

    public static function twilioConfigured(): bool
    {
        return filled(config('docchat.calls.twilio.account_sid')) && filled(config('docchat.calls.twilio.auth_token'));
    }
}
